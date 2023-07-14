<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     * @param MailerInterface $mailer
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id): array
    {
        $user = User::find($user_id);
        $user_type = '';
        $emergency_jobs = array();
        $normal_jobs = array();
        $jobs = array();

        if ($user) {
            if ($user->is('customer')) {
                $jobs = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                    ->whereIn('status', ['pending', 'assigned', 'started'])
                    ->orderBy('due', 'asc')
                    ->get()
                    ->all();
                $user_type = 'customer';
            } elseif ($user->is('translator')) {
                $jobs = Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
                $user_type = 'translator';
            }
        }
        if (!empty($jobs)) {
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes') {
                    $emergency_jobs[] = $jobItem;
                } else {
                    $normal_jobs[] = $jobItem;
                }
            }
            $normal_jobs = collect($normal_jobs)->map(function ($item) use ($user_id) {
                $item['user_check'] = Job::checkParticularJob($user_id, $item);
                return $item;
            })->sortBy('due')->all();
        }

        return ['emergency_jobs' => $emergency_jobs, 'normal_jobs' => $normal_jobs, 'user' => $user, 'user_type' => $user_type];
    }

    /**
     * @param $userId
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request): array
    {
        $page = $request->get('page', 1);
        $num_pages = 0;
        $user_type = '';
        $emergency_jobs = array();
        $jobs = array();

        $user = User::find($userId);
        if ($user) {
            if ($user->is('customer')) {
                $jobs = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);
                $user_type = 'customer';
            } elseif ($user->is('translator')) {
                $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $page);
                $num_pages = ceil($jobs->total() / 15);
                $user_type = 'translator';
            }
        }

        return ['emergency_jobs' => $emergency_jobs, 'jobs' => $jobs, 'user' => $user, 'user_type' => $user_type, 'num_pages' => $num_pages, 'pageNum' => $page];
    }

    /**
     * @param $user
     * @param $data
     * @return array
     */
    public function store($user, $data): array
    {
        $immediate_time = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }

                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            } else {
                $due_carbon = Carbon::now()->addMinute($immediate_time);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            }

            if (isset($data['duration']) && $data['duration'] == '') {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "duration";
                return $response;
            }

            $data['customer_phone_type'] = $data['customer_phone_type'] ? 'yes' : 'no';

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }
            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = $data['by_admin'] ?? 'no';

            $job = $user->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            $this->setJobFor($job, $data);

            $data['customer_town'] = $user->userMeta->city;
            $data['customer_type'] = $user->userMeta->customer_type;
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = $data['reference'] ?? '';
        $user = $job->user()->get()->first();
        $job->address = (isset($data['address']) && $data['address'] != '') ? $data['address'] : $user->userMeta->address;
        $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
        $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        $job->save();

        $name = $user->name;
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $sendData = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return [
            'type' => $user_type,
            'job' => $job,
            'status' => 'success'
        ];

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job): array
    {
        $data = $this->getJobArr($job);

        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id): array
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $user_language = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $user_language, $gender, $translator_level);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $job_user_id = $job->user_id;
            $check_town = Job::checkTowns($job_user_id, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$check_town) {
                unset($job_ids[$k]);
            }
        }
        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $exclude_user_id, array $data = [])
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and returns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job): int
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?: $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        $message = '';
        // analyse whether its phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id): bool
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id): bool
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        return $not_get_notification != 'yes';
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", $onesignalAppID);

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $android_sound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
            $ios_sound = $data['immediate'] == 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        $fields = array(
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'title' => array('en' => 'DigitalTolk'),
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $android_sound,
            'ios_sound' => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;

        $translator_type = '';
        if ($job_type == 'paid')
            $translator_type = 'professional';
        else if ($job_type == 'rws')
            $translator_type = 'rwstranslator';
        else if ($job_type == 'unpaid')
            $translator_type = 'volunteer';

        $job_language = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();

        return User::getPotentialUsers($translator_type, $job_language, $gender, $translator_level, $translatorsId);
    }

    /**
     * @param $id
     * @param $data
     * @param $user
     * @return array
     */
    public function updateJob($id, $data, $user): array
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->whereNull('cancel_at')
            ->orWhereNotNull('completed_at')
            ->first();

        $log_data = [];

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged'])
            $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        $old_time = null;
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        $langChanged = $job->from_language_id != $data['from_language_id'];
        if ($langChanged) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $user->id . '(' . $user->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }

        return ['Updated'];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator): array
    {
        $old_status = $job->status;
        $statusChanged = $old_status != $data['status'];
        if ($statusChanged) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                return ['statusChanged' => true, 'log_data' => $log_data];
            }
        }

        return ['statusChanged' => false];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['session_time'] == '') return false;
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
        }

        return true;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data): bool
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data): bool
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translatorChanged = false;
        $log_data = [];
        $new_translator = null;
        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            if (!is_null($current_translator) && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
        }

        return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due): array
    {
        $log_data = [];
        $dateChanged = $old_due != $new_due;
        if ($dateChanged) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];

        }

        return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $data = $this->getJobArr($job);            // save job's information to data for sending Push

        $this->setJobFor($job, $data);
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all suitable translators
    }

    /**
     * @param $job
     * @return array
     */
    public function getJobArr($job): array
    {
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $dueDateTime = explode(" ", $job->due);
        $dueDate = $dueDateTime[0];
        $dueTime = $dueDateTime[1];
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        return $data;
    }

    /**
     * @param $job
     * @param array $data
     * @return void
     */
    public function setJobFor($job, array &$data)
    {
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                    break;
                case 'yes':
                    $data['job_for'][] = 'certified';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        $user_tags = [];
        foreach ($users as $oneUser) {
            $user_tags[] = '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        return '[' . implode(',{"operator": "OR"},', $user_tags) . ']';
    }

    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user): array
    {
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $this->sendJobAcceptedMail($job);
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($user);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $user): array
    {
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $this->sendJobAcceptedMail($job);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user): array
    {
        $response = array();
        /*@todo
            add 24hrs login here.
            If the cancellation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancellation is within 24
            if cancellation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancellation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = $job->withdraw_at->diffInHours($job->due) >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($user)
    {
        $user_meta = $user->userMeta;
        $job_type = 'unpaid';
        $translator_type = $user_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */

        $languages = UserLanguages::where('user_id', '=', $user->id)->get();
        $user_language = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($user->id, $job_type, 'pending', $user_language, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $job_user_id = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($user->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($user->id, $job);
            $check_town = Job::checkTowns($job_user_id, $user->id);

            if (($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') || ($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$check_town)
                unset($job_ids[$k]);
        }

        return $job_ids;
    }

    public function endJob($post_data): array
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if ($job_detail->status != 'started')
            return ['status' => 'success'];

        $this->setJobEndAt($job_detail, $completed_date, $interval, $job);
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completed_date;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data): array
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);
        $this->setJobEndAt($job_detail, $completed_date, $interval, $job);
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completed_date;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null): array
    {
        $request_data = $request->all();
        $user = $request->__authenticatedUser;
        $consumer_type = $user->consumer_type;

        $allJobs = Job::query();
        if ($user && $user->user_type == env('SUPERADMIN_ROLE_ID')) {

            if (isset($request_data['feedback']) && $request_data['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($request_data['count']) && $request_data['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($request_data['id']) && $request_data['id'] != '') {
                if (is_array($request_data['id']))
                    $allJobs->whereIn('id', $request_data['id']);
                else
                    $allJobs->where('id', $request_data['id']);
                $request_data = array_only($request_data, ['id']);
            }

            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $allJobs->whereIn('from_language_id', $request_data['lang']);
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $allJobs->whereIn('status', $request_data['status']);
            }
            if (isset($request_data['expired_at']) && $request_data['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $request_data['expired_at']);
            }
            if (isset($request_data['will_expire_at']) && $request_data['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $request_data['will_expire_at']);
            }
            if (isset($request_data['customer_email']) && count($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $request_data['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($request_data['translator_email']) && count($request_data['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $request_data['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            $this->filter($request_data, $allJobs);

            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $allJobs->whereIn('job_type', $request_data['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }

            if (isset($request_data['physical'])) {
                $allJobs->where('customer_physical_type', $request_data['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($request_data['phone'])) {
                $allJobs->where('customer_phone_type', $request_data['phone']);
                if (isset($request_data['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($request_data['flagged'])) {
                $allJobs->where('flagged', $request_data['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($request_data['distance']) && $request_data['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($request_data['salary']) && $request_data['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($request_data['count']) && $request_data['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($request_data['consumer_type']) && $request_data['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($request_data) {
                    $q->where('consumer_type', $request_data['consumer_type']);
                });
            }

            if (isset($request_data['booking_type'])) {
                if ($request_data['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($request_data['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

        } else {

            if (isset($request_data['id']) && $request_data['id'] != '') {
                $allJobs->where('id', $request_data['id']);
                $request_data = array_only($request_data, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($request_data['feedback']) && $request_data['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($request_data['count']) && $request_data['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($request_data['lang']) && $request_data['lang'] != '') {
                $allJobs->whereIn('from_language_id', $request_data['lang']);
            }
            if (isset($request_data['status']) && $request_data['status'] != '') {
                $allJobs->whereIn('status', $request_data['status']);
            }
            if (isset($request_data['job_type']) && $request_data['job_type'] != '') {
                $allJobs->whereIn('job_type', $request_data['job_type']);
            }
            if (isset($request_data['customer_email']) && $request_data['customer_email'] != '') {
                $user = DB::table('users')->where('email', $request_data['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }
            $this->filter($request_data, $allJobs);

        }
        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all')
            $allJobs = $allJobs->get();
        else
            $allJobs = $allJobs->paginate(15);
        return $allJobs;
    }

    public function reopen($request): array
    {
        $job_id = $request['jobid'];
        $user_id = $request['userid'];

        $job = Job::find($job_id);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $user_id;
        $data['job_id'] = $job_id;
        $data['cancel_at'] = Carbon::now();

        $data_reopen = array();
        $data_reopen['status'] = 'pending';
        $data_reopen['created_at'] = Carbon::now();
        $data_reopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data_reopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $job_id)->update($data_reopen);
            $new_jobid = $job_id;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $job_id;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        Translator::where('job_id', $job_id)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        }

        return ["Please try again!"];
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins(int $time, string $format = '%02dh %02dmin'): string
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

    /**
     * @param $job
     * @return array
     */
    public function sendJobAcceptedMail($job): array
    {
        $user = $job->user()->get()->first();
        $mailer = new AppMailer();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job
        ];
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
        return array($user, $data);
    }

    /**
     * @param $job_detail
     * @param $completed_date
     * @param $interval
     * @param $job
     * @return void
     */
    public function setJobEndAt($job_detail, $completed_date, &$interval, &$job)
    {
        $due_date = $job_detail->due;
        $start = date_create($due_date);
        $end = date_create($completed_date);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
    }

    /**
     * @param $request_data
     * @param $allJobs
     * @return void
     */
    public function filter($request_data, $allJobs)
    {
        if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "created") {
            if (isset($request_data['from']) && $request_data['from'] != "") {
                $allJobs->where('created_at', '>=', $request_data["from"]);
            }
            if (isset($request_data['to']) && $request_data['to'] != "") {
                $to = $request_data["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }
        if (isset($request_data['filter_timetype']) && $request_data['filter_timetype'] == "due") {
            if (isset($request_data['from']) && $request_data['from'] != "") {
                $allJobs->where('due', '>=', $request_data["from"]);
            }
            if (isset($request_data['to']) && $request_data['to'] != "") {
                $to = $request_data["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }
    }

}