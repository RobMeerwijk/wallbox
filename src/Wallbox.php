<?php

/**
 * PHP Wrapper to Interact with Wallbox API
 *
 * @version 1.1.1
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @see     https://github.com/dutche027/wallbox
 * @see     https://packagist.org/packages/dutchie027/wallbox
 */

namespace dutchie027\Wallbox;

use dutchie027\Wallbox\Exceptions\WallboxAPIRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class Wallbox
{
    /**
     * Version of the Library
     *
     * @const string
     */
    protected const LIBRARY_VERSION = '1.1.1';
    protected const MAX_RETRIES = 3;
    protected bool $failed;

    /**
     * Status IDs
     *
     * @const array<mixed>
     */
    protected const STATUS_LOOKUP = [
        164 => 'WAITING',
        180 => 'WAITING',
        181 => 'WAITING',
        183 => 'WAITING',
        184 => 'WAITING',
        185 => 'WAITING',
        186 => 'WAITING',
        187 => 'WAITING',
        188 => 'WAITING',
        189 => 'WAITING',
        193 => 'CHARGING',
        194 => 'CHARGING',
        161 => 'READY',
        162 => 'READY',
        178 => 'PAUSED',
        182 => 'PAUSED',
        177 => 'SCHEDULED',
        179 => 'SCHEDULED',
        196 => 'DISCHARGING',
        14 => 'ERROR',
        15 => 'ERROR',
        0 => 'DISCONNECTED',
        163 => 'DISCONNECTED',
        209 => 'LOCKED',
        210 => 'LOCKED',
        165 => 'LOCKED',
        166 => 'UPDATING',
    ];

    /**
     * Root of the API
     *
     * @const string
     */
    protected const API_URL = 'https://api.wall-box.com';

    /**
     * Root of the LOGIN API
     *
     * @const string
     */
    protected const API_LOGIN = 'https://user-api.wall-box.com';

    /**
     * URI for Legacy Auth
     *
     * @const string
     */
    protected const LEGACY_AUTH_URI = '/auth/token/user';

    /**
     * URI for new Auth against new login URL
     *
     * @const string
     */
    protected const AUTH_URI = '/users/signin';

    /**
     * URI for Listing/Querying Data
     *
     * @const string
     */
    protected const LIST_URI = '/v3/chargers/groups';

    /**
     * URI specific for charger status
     *
     * @const string
     */
    protected const CHARGER_STATUS_URI = '/chargers/status/';

    /**
     * URI for Acting on a charger
     * NOTE: This is a v2 URI and uses PUT vs GET
     *
     * @const string
     */
    protected const CHARGER_ACTION_URI = '/v2/charger/';

    /**
     * URI for Acting on a charger
     *
     * @const string
     */
    protected const CHARGER_SESSION_ACTION_URI = '/v3/chargers/';

    /**
     * URI for Getting Session Data
     *
     * @const string
     */
    protected const SESSION_LIST_URI = '/v4/sessions/stats';

    /**
     * JWT Token
     *
     * @var string
     */
    protected $p_jwt;

    /**
     * The Guzzle HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    public $guzzle;

    /**
     * The Config class
     *
     * @var Config
     */
    public $config;

    /**
     * The Push class
     *
     * @var Push
     */
    public $push;

    /**
     * ID Of the current status
     *
     * @var int
     */
    private $currentStatus = 0;

    private float $reauthTTL = 0;

    /**
     * Default constructor
     */
    public function __construct(string $configLoc = null)
    {
        $this->config = null === $configLoc ? new Config() : new Config($configLoc);

        $stack = HandlerStack::create();
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));

        $this->guzzle = new Client(['handler' => $stack]);
        $this->usernamePasswordAuth();
    }

    /**
     * getStatusName
     * Returns the name of the status associated with the ID
     */
    public function getStatusName(int $id): string
    {
        return self::STATUS_LOOKUP[$id];
    }

    /**
     * checkFirmwareStatus
     */
    public function checkFirmwareStatus(int $id): string
    {
        $chargerConfig = json_decode($this->getFullChargerStatus($id));
        $cv = $chargerConfig->config_data->software->currentVersion;
        $lv = $chargerConfig->config_data->software->latestVersion;
        $ua = $chargerConfig->config_data->software->updateAvailable;

        if ($cv == $lv && !$ua) {
            return 'Firmware up to date';
        }

        return 'Firmware needs updated';
    }

    /**
     * checkLock
     */
    public function checkLock(int $id): bool
    {
        return json_decode($this->getFullChargerStatus($id))->config_data->locked === 1 ? true : false;
    }

    /**
     * getJWTToken
     * Returns the stored JWT Token
     */
    protected function getJWTToken(): string
    {
        return $this->p_jwt;
    }

    /**
     * getThisMonthUsage
     */
    public function getThisMonthUsage(int $id): string
    {
        return $this->convertSeconds($this->returnUsageInMinutes($this->getThisMonthData($id)));
    }

    /**
     * getThisYearUsage
     */
    public function getThisYearUsage(int $id): string
    {
        return $this->convertSeconds($this->returnUsageInMinutes($this->getThisYearData($id)));
    }

    /**
     * getAllTimeUsage
     */
    public function getAllTimeUsage(int $id): string
    {
        return $this->convertSeconds($this->returnUsageInMinutes($this->getAllTimeData($id)));
    }

    /**
     * getSevenDaysUsage
     */
    public function getSevenDaysUsage(int $id): string
    {
        return $this->convertSeconds($this->returnUsageInMinutes($this->getLastSevenDaysData($id)));
    }

    /**
     * getLastMonthUsage
     */
    public function getLastMonthUsage(int $id): string
    {
        return $this->convertSeconds($this->returnUsageInMinutes($this->getLastMonthData($id)));
    }

    /**
     * getLastMonthPluggedIn
     */
    public function getLastMonthPluggedIn(int $id): string
    {
        return $this->convertSeconds($this->returnPluggedInInMinutes($this->getLastMonthData($id)));
    }

    /**
     * getThisMonthPluggedIn
     */
    public function getThisMonthPluggedIn(int $id): string
    {
        return $this->convertSeconds($this->returnPluggedInInMinutes($this->getThisMonthData($id)));
    }

    /**
     * getThisMonthCount
     */
    public function getThisMonthCount(int $id): int
    {
        return count($this->getThisMonthData($id)->data);
    }

    /**
     * getThisMonthEnergyUsage
     */
    public function getThisMonthEnergyUsage(int $id): float
    {
        return $this->returnEnergyUsage($this->getThisMonthData($id));
    }

    /**
     * getLastMonthEnergyUsage
     */
    public function getLastMonthEnergyUsage(int $id): float
    {
        return $this->returnEnergyUsage($this->getLastMonthData($id));
    }

    /**
     * getThisYearEnergyUsage
     */
    public function getThisYearEnergyUsage(int $id): float
    {
        return $this->returnEnergyUsage($this->getThisYearData($id));
    }

    /**
     * getAllTimeEnergyUsage
     */
    public function getAllTimeEnergyUsage(int $id): float
    {
        return $this->returnEnergyUsage($this->getAllTimeData($id));
    }

    /**
     * getLastMonthCount
     */
    public function getLastMonthCount(int $id): int
    {
        return count($this->getLastMonthData($id)->data);
    }

    /**
     * getThisMonthData
     */
    private function getThisMonthData(int $id): \stdClass
    {
        return json_decode($this->getStats($id, strtotime('first day of this month midnight', time()), time()));
    }

    /**
     * getLastMonthData
     */
    private function getLastMonthData(int $id): \stdClass
    {
        return json_decode($this->getStats($id, strtotime('first day of last month midnight', time()), strtotime('first day of this month midnight', time())));
    }

    /**
     * getLastSevenDaysData
     */
    private function getLastSevenDaysData(int $id): \stdClass
    {
        return json_decode($this->getStats($id, strtotime('-8 days midnight', time()), strtotime('-1 day midnight', time())));
    }

    /**
     * getThisYearData
     */
    private function getThisYearData(int $id): \stdClass
    {
        return json_decode($this->getStats($id, strtotime('1/1/' . date('Y'), time()), strtotime('12/31/' . date('Y'), time())));
    }

    /**
     * getAllTimeData
     */
    private function getAllTimeData(int $id): \stdClass
    {
        return json_decode($this->getStats($id, strtotime('1/1/1970', time()), time() ));
    }

    /**
     * returnUsageInMinutes
     */
    private function returnUsageInMinutes(\stdClass $data): int
    {
        $seconds = 0;

        foreach ($data->data as $unit) {
            $seconds += ($unit->attributes->time);
        }

        return $seconds;
    }

    /**
     * returnEnergyUsage
     */
    private function returnEnergyUsage(\stdClass $data): float
    {
        $energy = 0;

        foreach ($data->data as $unit) {
            $energy += ($unit->attributes->energy);
        }

        return $energy;
    }

    /**
     * returnPluggedInInMinutes
     */
    private function returnPluggedInInMinutes(\stdClass $data): int
    {
        $seconds = 0;

        foreach ($data->data as $unit) {
            $seconds += ($unit->attributes->end - $unit->attributes->start);
        }

        return $seconds;
    }

    /**
     * getStats
     * Calls Stats URI and gets data between start and end
     */
    public function getStats(int $id, int $start, int $end, int $limit = 1000): string
    {
        $payload = [
            'charger' => $id,
            'start_date' => $start,
            'end_date' => $end,
            'limit' => $limit,
        ];
        $httpPayload = http_build_query($payload);

        $URL = self::API_URL . self::SESSION_LIST_URI . '?' . $httpPayload;

        return $this->makeAPICall('GET', $URL);
    }

    /**
     * getFullChargerStatus
     * Returns full data about charger
     */
    public function getFullChargerStatus(int $id): string
    {
        return $this->makeAPICall('GET', self::API_URL . self::CHARGER_STATUS_URI . $id);
    }

    /**
     * getChargerStatusID
     * Returns full data about charger
     */
    public function getChargerStatusID(int $id): int
    {
        return json_decode($this->getChargerData($id))->data->chargerData->status;
    }

    /**
     * getFullPayload
     */
    public function getFullPayload(): string
    {
        return $this->makeAPICall('GET', self::API_URL . self::LIST_URI);
    }

    /**
     * setHeaders
     * Sets the headers using the API Token
     *
     * @return array<mixed>
     */
    public function setHeaders(bool $bearer = true): array
    {
        $array = [
            'User-Agent' => 'php-api-dutchie027/' . self::LIBRARY_VERSION,
            'Content-Type' => 'application/json;charset=utf-8',
            'Accept' => 'application/json, text/plain, */*',
        ];

        if ($bearer) {
            $array['Authorization'] = 'Bearer ' . $this->getJWTToken();
        } else {
            $array['Partner'] = 'wallbox';
            $array['Authorization'] = 'Basic ' . $this->config->getToken();
        }

        return $array;
    }

    /**
     * usernamePasswordAuth
     */
    private function usernamePasswordAuth(): void
    {
        $payload = json_decode($this->makeAPICall('GET', self::API_LOGIN . self::AUTH_URI, false));
        $this->p_jwt = $payload->data->attributes->token;
        $this->reauthTTL = $payload->data->attributes->ttl;
    }

    /**
     * reAuth
     */
    public function reAuth(): void
    {
        $this->usernamePasswordAuth();
    }

    /**
     * getLastChargeDuration
     */
    public function getLastChargeDuration(): string
    {
        return $this->convertSeconds(json_decode($this->getFullPayload(), true)['result']['groups'][0]['chargers'][0]['chargingTime']);
    }

    /**
     * unlockCharger
     */
    public function unlockCharger(int $id): void
    {
        $this->makeAPICall('PUT', self::API_URL . self::CHARGER_ACTION_URI . $id, true, '{"locked":0}');
    }

    /**
     * lockCharger
     */
    public function lockCharger(int $id): void
    {
        $this->makeAPICall('PUT', self::API_URL . self::CHARGER_ACTION_URI . $id, true, '{"locked":1}');
    }

    /**
     * getChargerData
     */
    public function getChargerData(int $id): string
    {
        return $this->makeAPICall('GET', self::API_URL . self::CHARGER_ACTION_URI . $id);
    }

    /**
     * getTotalChargeTime
     */
    public function getTotalChargeTime(int $id): string
    {
        return $this->convertSeconds(json_decode($this->getChargerData($id))->data->chargerData->resume->chargingTime);
    }

    /**
     * getTotalSessions
     */
    public function getTotalSessions(int $id): string
    {
        return json_decode($this->getChargerData($id))->data->chargerData->resume->totalSessions;
    }

    /**
     * addedEnergy (currrent charging session)
     */
    public function addedEnergy(int $ID): float
    {
       $chargerStatus = json_decode($this->getFullChargerStatus($ID));
       return ($this->getStatusName($chargerStatus->status_id) == 'CHARGING' || 
               $this->getStatusName($chargerStatus->status_id) == 'DISCHARGING' || 
               $this->getStatusName($chargerStatus->status_id) == 'PAUSED' ) ?
                      $chargerStatus->added_energy : 0;
    }
    
    /**
     * makeAPICall
     * Makes the API Call
     */
    public function makeAPICall(string $type, string $url, bool $token = true, string|null $body = null): string
    {
        $data['headers'] = $this->setHeaders($token);
        $data['body'] = $body;

        try {
            $request = $this->guzzle->request($type, $url, $data);

            return $request->getBody()->getContents();
        } catch (RequestException $e) {
            $httpCode = $e->getCode();
            Log::error('A ' . $httpCode . ' error occurred while performing the request to ' . $url);

            throw new WallboxAPIRequestException('A ' . $httpCode . ' error occurred while performing the request to ' . $url);
        }
    }

    /**
     * convertSeconds
     * Returns a referencd to the logger
     */
    public function convertSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $seconds = $seconds % 60;

        return $hours > 0 ? "{$hours}h {$minutes}m" : ($minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s");
    }

    /**
     * monitor
     */
    public function monitor(int $id, int $seconds = 30): void
    {
        Log::info('Monitor() has been called. This will put the script in an infinite loop.');

        while (true) {
            $statusID = $this->getChargerStatusID($id);
            $sendPush = false;
            $title = $body = '';

            Log::debug('Running in monitor mode...Polling. Current Status: ' . $this->currentStatus . ' Previous Status: ' . $statusID);

            if ($this->currentStatus == 0) {
                $sendPush = true;
                $title = 'Wallbox monitoring started';
                $body = 'The wallbox monitor has just started. The current status is ' . $this->getStatusName($statusID);
                $this->currentStatus = $statusID;
            } elseif ($this->currentStatus != $statusID) {
                $sendPush = true;
                $title = 'Wallbox Status Change: ' . $this->getStatusName($this->currentStatus) . ' to ' . $this->getStatusName($statusID);

                if ($this->currentStatus == 193 || $this->currentStatus == 194) {
                    Log::debug('Went from charging to not charging anymore...');
                    $duration = $this->getLastChargeDuration();

                    $body = 'Total charge time ' . $duration;
                } elseif ($statusID == 193 || $statusID == 194) {
                    Log::debug('Went from not charging to charging...');
                    $body = 'Wallbox now charging...';
                } else {
                    Log::debug('Status change...');
                    $body = 'Status update only...';
                }
                $this->currentStatus = $statusID;
            }

            if ($sendPush) {
                Log::info('Sending push - ' . $title . '  - ' . $body);
                $this->pushover()->sendPush($title, $body);
            }

            sleep(Config::getServiceTimeout());
        }
    }

    /**
     * pushover
     * Pointer to the \Push class
     */
    public function pushover(): Push
    {
        return new Push();
    }

    protected function retryDecider(): callable
    {
        return static function (
            int $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                Log::warning('Timeout encountered, retrying');

                return true;
            }

            if ($response) {
                // Retry on server errors
                if ($response->getStatusCode() >= 500) {
                    Log::warning('Server 5xx error encountered, retrying...');

                    return true;
                }
            }

            return false;
        };
    }

    /**
     * delay 1s 2s 3s 4s 5s ...
     */
    protected function retryDelay(): callable
    {
        return static function (int $numberOfRetries): int {
            return 1000 * $numberOfRetries;
        };
    }
}
