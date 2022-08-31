<?php

/**
 * PHP Wrapper to Interact with Wallbox API
 *
 * @version 2.0
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @see     https://github.com/dutche027/wallbox
 * @see     https://packagist.org/packages/dutchie027/wallbox
 */

namespace dutchie027\Wallbox;

use dutchie027\Wallbox\Exceptions\WallboxAPIRequestException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Wallbox
{
    /**
     * Version of the Library
     *
     * @const string
     */
    protected const LIBRARY_VERSION = '0.1.0';

    /**
     * Status IDs
     *
     * @const array
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
     * Log Directory
     *
     * @var string
     */
    protected $p_log_location;

    /**
     * JWT Token
     *
     * @var string
     */
    protected $p_jwt;

    /**
     * Base 64 Token
     *
     * @var string
     */
    protected $p_token;

    /**
     * Log Reference
     *
     * @var string
     */
    protected $p_log;

    /**
     * Log Name
     *
     * @var string
     */
    protected $p_log_name;

    /**
     * Log File Tag
     *
     * @var string
     */
    protected $p_log_tag = 'wallbox';

    /**
     * Log Types
     *
     * @var array
     */
    protected $log_literals = [
        'debug',
        'info',
        'notice',
        'warning',
        'critical',
        'error',
    ];

    /**
     * The Guzzle HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    public $guzzle;

    /**
     * Default constructor
     */
    public function __construct($user, $password, array $attributes = [], Guzzle $guzzle = null)
    {
        $tokenString = $user . ':' . $password;
        $base64 = base64_encode($tokenString);
        $this->p_token = $base64;

        if (isset($attributes['log_dir']) && is_dir($attributes['log_dir'])) {
            $this->p_log_location = $attributes['log_dir'];
        } else {
            $this->p_log_location = sys_get_temp_dir();
        }

        if (isset($attributes['log_name'])) {
            $this->p_log_name = $attributes['log_name'];

            if (!preg_match("/\.log$/", $this->p_log_name)) {
                $this->p_log_name .= '.log';
            }
        } else {
            $this->p_log_name = $this->pGenRandomString() . '.' . time() . '.log';
        }

        if (isset($attributes['log_tag'])) {
            $this->p_log = new Logger($attributes['log_tag']);
        } else {
            $this->p_log = new Logger($this->p_log_tag);
        }

        if (isset($attributes['log_level']) && in_array($attributes['log_level'], $this->log_literals, true)) {
            if ($attributes['log_level'] == 'debug') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Debug));
            } elseif ($attributes['log_level'] == 'info') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Info));
            } elseif ($attributes['log_level'] == 'notice') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Notice));
            } elseif ($attributes['log_level'] == 'warning') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Warning));
            } elseif ($attributes['log_level'] == 'error') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Error));
            } elseif ($attributes['log_level'] == 'critical') {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Critical));
            } else {
                $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Warning));
            }
        } else {
            $this->p_log->pushHandler(new StreamHandler($this->pGetLogPath(), \Monolog\Level::Info));
        }
        $this->guzzle = $guzzle ?: new Guzzle();
        $this->usernamePasswordAuth();
    }

    /**
     * getLogLocation
     * Alias to Get Log Path
     */
    public function getLogLocation(): string
    {
        return $this->pGetLogPath();
    }

    /**
     * getStatusName
     * Returns the name of the status associated with the ID
     */
    public function getStatusName($id): string
    {
        return self::STATUS_LOOKUP[$id];
    }

    /**
     * getStatusName
     * Returns the name of the status associated with the ID
     *
     * @param int $id
     */
    public function checkFirmwareStatus($id): string
    {
        $chargerConfig = json_decode($this->getChargerStatus($id));
        $cv = $chargerConfig->config_data->software->currentVersion;
        $lv = $chargerConfig->config_data->software->latestVersion;
        $ua = $chargerConfig->config_data->software->updateAvailable;

        if ($cv == $lv && !$ua) {
            return 'Firmware up to date';
        }

        return 'Firmware needs updated';
    }

    /**
     * getStatusName
     * Returns the name of the status associated with the ID
     *
     * @param int $id
     */
    public function checkLock($id): bool
    {
        $chargerConfig = json_decode($this->getChargerStatus($id));
        $locked = $chargerConfig->config_data->locked;

        return $locked == 1 ? true : false;
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
     * getLogPointer
     * Returns a referencd to the logger
     */
    public function getLogPointer(): string
    {
        return $this->p_log;
    }

    /**
     * getStats
     * Calls Stats URI and gets data between start and end
     *
     * @param int $id
     * @param int $start
     * @param int $end
     */
    public function getStats($id, $start, $end): string
    {
        $payload = [
            'charger' => $id,
            'start_date' => $start,
            'end_date' => $end,
        ];
        $httpPayload = http_build_query($payload);

        $URL = self::API_URL . self::SESSION_LIST_URI . '?' . $httpPayload;

        return $this->makeAPICall('GET', $URL);
    }

    /**
     * getChargerStatus
     * Returns full data about charger
     *
     * @param int $id
     */
    public function getChargerStatus($id): string
    {
        $URL = self::API_URL . self::CHARGER_STATUS_URI . $id;

        return $this->makeAPICall('GET', $URL);
    }

    /**
     * pGetLogPath
     * Returns full path and name of the log file
     */
    protected function pGetLogPath(): string
    {
        return $this->p_log_location . '/' . $this->p_log_name;
    }

    /**
     * getFullPayload
     */
    public function getFullPayload(): string
    {
        $URL = self::API_URL . self::LIST_URI;

        return $this->makeAPICall('GET', $URL);
    }

    /**
     * setHeaders
     * Sets the headers using the API Token
     *
     * @param bool $bearer
     */
    public function setHeaders($bearer = true): array
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
            $array['Authorization'] = 'Basic ' . $this->p_token;
        }

        return $array;
    }

    /**
     * usernamePasswordAuth
     */
    private function usernamePasswordAuth(): void
    {
        $authURL = self::API_LOGIN . self::AUTH_URI;
        $this->p_jwt = json_decode($this->makeAPICall('GET', $authURL, false))->data->attributes->token;
    }

    /**
     * getLastChargeDuration
     */
    public function getLastChargeDuration()
    {
        $data = json_decode($this->getFullPayload(), true);

        return $this->convertSeconds($data['result']['groups'][0]['chargers'][0]['chargingTime']);
    }

    /**
     * unlockCharger
     *
     * @param int $id
     */
    public function unlockCharger($id): void
    {
        $URL = self::API_URL . self::CHARGER_ACTION_URI . $id;
        $body = '{"locked":0}';
        $this->makeAPICall('PUT', $URL, true, $body);
    }

    /**
     * lockCharger
     *
     * @param int $id
     */
    public function lockCharger($id): void
    {
        $URL = self::API_URL . self::CHARGER_ACTION_URI . $id;
        $body = '{"locked":1}';
        $this->makeAPICall('PUT', $URL, true, $body);
    }

    /**
     * getChargerData

     *
     * @param int $id
     */
    public function getChargerData($id): string
    {
        $URL = self::API_URL . self::CHARGER_ACTION_URI . $id;

        return $this->makeAPICall('GET', $URL);
    }

    /**
     * getTotalChargeTime
     *
     * @param int $id
     */
    public function getTotalChargeTime($id): string
    {
        $data = json_decode($this->getChargerData($id));

        return $this->convertSeconds($data->data->chargerData->resume->chargingTime);
    }

    /**
     * getTotalSessions
     */
    public function getTotalSessions($id): string
    {
        $data = json_decode($this->getChargerData($id));

        return $data->data->chargerData->resume->totalSessions;
    }

    /**
     * pGenRandomString
     * Generates a random string of $length
     *
     * @param int $length
     */
    public function pGenRandomString($length = 6): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; ++$i) {
            $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * makeAPICall
     * Makes the API Call
     *
     * @param $type string GET|POST|DELETE|PATCH
     * @param $url string endpoint
     * @param $body string - usually passed as JSON
     *
     * @throws WallboxAPIRequestException Exception with details regarding the failed request
     *
     */
    public function makeAPICall($type, $url, $token = true, $body = null): string
    {
        $data['headers'] = $this->setHeaders($token);
        $data['body'] = $body;

        try {
            $request = $this->guzzle->request($type, $url, $data);

            return $request->getBody()->getContents();
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $ja = $response->getBody()->getContents();

                throw new WallboxAPIRequestException('An error occurred while performing the request to ' . $url . ' -> ' . ($ja['error'] ?? json_encode($ja)));
            }

            throw new WallboxAPIRequestException('An unknown error ocurred while performing the request to ' . $url);
        }
    }

    /**
     * convertSeconds
     * Returns a referencd to the logger
     */
    public function convertSeconds($seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $seconds = $seconds % 60;

        return $hours > 0 ? "{$hours}h {$minutes}m" : ($minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s");
    }
}
