<?php

namespace Courier;

use Capsule\Request;
use DateTime;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Shuttle\Shuttle;

final class CourierClient implements CourierClientInterface
{
    /**
     * @var string Library version, used for setting User-Agent
     */
    private $version = '1.2.0';

    /**
     * Courier API base url.
     *
     * @var string
     */
    private $base_url = "https://api.courier.com/";

    /**
     * Courier authorization token.
     *
     * @var string
     */
    private $auth_token;

    /**
     * Courier username.
     *
     * @var string
     */
    private $username;

    /**
     * Courier password.
     *
     * @var string
     */
    private $password;

    /**
     * Courier authorization header.
     *
     * @var array
     */
    private $authorization;

    /**
     * PSR-18 ClientInterface instance.
     *
     * @var ClientInterface|null
     */
    private $httpClient;

    /**
     * Courier client constructor.
     *
     * @param string|null $base_url
     * @param string|null $auth_token
     * @param string|null $username
     * @param string|null $password
     */
    public function __construct(string $base_url = NULL, string $auth_token = NULL, string $username = NULL, string $password = NULL)
    {
        # Override base_url if passed as a param or set as an environment variable
        if ($base_url) {
            $this->base_url = $base_url;
        } else if (getenv('COURIER_BASE_URL')) {
            $this->base_url = getenv('COURIER_BASE_URL');
        }

        # Token Auth takes precedence; If no token auth, then Basic Auth
        if ($auth_token) {
            $this->auth_token = $auth_token;
            $this->authorization = [
                'type' => 'Bearer',
                'token' => $auth_token,
            ];
        } else if (getenv('COURIER_AUTH_TOKEN')) {
            $this->auth_token = getenv('COURIER_AUTH_TOKEN');
            $this->authorization = [
                'type' => 'Bearer',
                'token' => getenv('COURIER_AUTH_TOKEN'),
            ];
        } else if ($username and $password) {
            $this->username = $username;
            $this->password = $password;
            $this->authorization = [
                'type' => 'Basic',
                'token' => base64_encode($username . ':' . $password),
            ];
        } else if (getenv('COURIER_AUTH_USERNAME') and getenv('COURIER_AUTH_PASSWORD')) {
            $this->username = getenv('COURIER_AUTH_USERNAME');
            $this->password = getenv('COURIER_AUTH_PASSWORD');
            $this->authorization = [
                'type' => 'Basic',
                'token' => base64_encode(getenv('COURIER_AUTH_USERNAME') . ':' . getenv('COURIER_AUTH_PASSWORD')),
            ];
        }
    }

    /**
     * Get the current authorization header.
     *
     * @return string
     */
    protected function getAuthorizationHeader(): string
    {
        return $this->authorization['type'] . ' ' . $this->authorization['token'];
    }

    /**
     * Set the HTTP client to use.
     *
     * @param ClientInterface $clientInterface
     * @return void
     */
    public function setHttpClient(ClientInterface $clientInterface): void
    {
        $this->httpClient = $clientInterface;
    }

    /**
     * Get the HTTP Client interface.
     *
     * @return ClientInterface
     */
    private function getHttpClient(): ClientInterface
    {
        if( empty($this->httpClient) ){
            $this->httpClient = new Shuttle;
        }

        return $this->httpClient;
    }

    /**
     * Process the request and decode response as JSON.
     *
     * @param RequestInterface $request
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    private function doRequest(RequestInterface $request): object
    {
        $response = $this->getHttpClient()->sendRequest($request);

        if( $response->getStatusCode() < 200 || $response->getStatusCode() >= 300 ){
            throw new CourierRequestException($response);
        }

        return \json_decode($response->getBody()->getContents());
	}

    /**
     * Build a PSR-7 Request instance.
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @return RequestInterface|Request
     */
    private function buildRequest(string $method, string $path, array $params = []): RequestInterface
    {
        return new Request(
            $method,
            $this->base_url . $path,
            \json_encode($params),
            [
                "Authorization" => $this->getAuthorizationHeader(),
                "Content-Type" => "application/json",
                'User-Agent' => 'courier-php/'.$this->version
            ]
        );
    }

    /**
     * Build a PSR-7 Request instance with Idempotency Key.
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param string|null $idempotency_key
     * @return RequestInterface|Request
     */
    private function buildIdempotentRequest(string $method, string $path, array $params = [], string $idempotency_key = NULL): RequestInterface
    {
        return new Request(
            $method,
            $this->base_url . $path,
            \json_encode($params),
            [
                "Authorization" => $this->getAuthorizationHeader(),
                "Content-Type" => "application/json",
                'User-Agent' => 'courier-php/'.$this->version,
                'Idempotency-Key' => $idempotency_key
            ]
        );
    }

    /**
     * Send a notification to a specified recipient.
     *
     * @param string $event
     * @param string $recipient
     * @param string|null $brand
     * @param object|null $profile
     * @param object|null $data
     * @param object|null $preferences
     * @param object|null $override
     * @param string|null $idempotency_key
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function sendNotification(string $event, string $recipient, string $brand = NULL, object $profile = NULL, object $data = NULL, object $preferences = NULL, object $override = NULL, string $idempotency_key = NULL): object
    {
        $params = array(
            'event' => $event,
            'recipient' => $recipient,
            'brand' => $brand,
            'profile' => $profile,
            'data' => $data,
            'preferences' => $preferences,
            'override' => $override
        );

        $params = array_filter($params);

        return $this->doRequest(
            $idempotency_key ? $this->buildIdempotentRequest("post", "send", $params, $idempotency_key)
            : $this->buildRequest("post", "send", $params)
        );
    }

    /**
     * Send a notification to list(s) subscribers
     *
     * @param string $event
     * @param string|null $list
     * @param string|null $pattern
     * @param string|null $brand
     * @param object|null $data
     * @param object|null $override
     * @param string|null $idempotency_key
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function sendNotificationToList(string $event, string $list = NULL, string $pattern = NULL, string $brand = NULL, object $data = NULL, object $override = NULL, string $idempotency_key = NULL): object
    {
        if ((!$list and !$pattern) or ($list and $pattern)) {
            throw new CourierRequestException("list.send requires a list id or a pattern");
        }

        $params = array(
            'event' => $event,
            'list' => $list,
            'pattern' => $pattern,
            'brand' => $brand,
            'data' => $data,
            'override' => $override
        );

        $params = array_filter($params);

        return $this->doRequest(
            $idempotency_key ? $this->buildIdempotentRequest("post", "send/list", $params, $idempotency_key)
            : $this->buildRequest("post", "send/list", $params)
        );
    }

    /**
     *  Fetch the statuses of messages you've previously sent.
     *
     * @param string|null $cursor
     * @param string|null $event
     * @param string|null $list
     * @param string|null $message_id
     * @param string|null $notification
     * @param string|null $recipient
     * @return object
     * @throws CourierRequestException
     */
    public function getMessages(string $cursor = NULL, string $event = NULL, string $list = NULL, string $message_id = NULL, string $notification = NULL, string $recipient = NULL): object
    {
        $query_params = array(
            'cursor' => $cursor,
            'event' => $event,
            'list' => $list,
            'message_id' => $message_id,
            'notification' => $notification,
            'recipient' => $recipient
        );

        return $this->doRequest(
            $this->buildRequest("get", "messages?" . http_build_query($query_params, null, '&', PHP_QUERY_RFC3986))
        );
    }

    /**
     *  Fetch the status of a message you've previously sent.
     *
     * @param string $message_id
     * @return object
     * @throws CourierRequestException
     */
    public function getMessage(string $message_id): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "messages/" . $message_id)
        );
    }

    /**
     *  Fetch the array of events of a message you've previously sent.
     *
     * @param string $message_id
     * @param string|null $type
     * @return object
     * @throws CourierRequestException
     */
    public function getMessageHistory(string $message_id, string $type = NULL): object
    {
        $path = "messages/" . $message_id . "/history";

        if ($type) {
            $path = $path . "?type=" . $type;
        }

        return $this->doRequest(
            $this->buildRequest("get", $path)
        );
    }

    /**
     *  Get the list of lists
     * @param string|null $cursor
     * @param string|null $pattern
     * @return object
     * @throws CourierRequestException
     */
    public function getLists(string $cursor = NULL, string $pattern = NULL): object
    {
        $query_params = array(
            'cursor' => $cursor,
            'pattern' => $pattern
        );

        return $this->doRequest(
            $this->buildRequest("get", "lists?" . http_build_query($query_params, null, '&', PHP_QUERY_RFC3986))
        );
    }

    /**
     *  Get the list items.
     *
     * @param string $list_id
     * @return object
     * @throws CourierRequestException
     */
    public function getList(string $list_id): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "lists/" . $list_id)
        );
    }

    /**
     *  Create or replace an existing list with the supplied values.
     *
     * @param string $list_id
     * @param string $name
     * @return object
     * @throws CourierRequestException
     */
    public function putList(string $list_id, string $name): object
    {
        $params = array(
            'name' => $name
        );

        return $this->doRequest(
            $this->buildRequest("put", "lists/" . $list_id, $params)
        );
    }

    /**
     *  Delete a list by list ID.
     *
     * @param string $list_id
     * @return object
     * @throws CourierRequestException
     */
    public function deleteList(string $list_id): object
    {
        return $this->doRequest(
            $this->buildRequest("delete", "lists/" . $list_id)
        );
    }

    /**
     *  Restore an existing list.
     *
     * @param string $list_id
     * @return object
     * @throws CourierRequestException
     */
    public function restoreList(string $list_id): object
    {
        return $this->doRequest(
            $this->buildRequest("put", "lists/" . $list_id . "/restore")
        );
    }

    /**
     *  Get the list's subscriptions
     *
     * @param string $list_id
     * @param string|null $cursor
     * @return object
     * @throws CourierRequestException
     */
    public function getListSubscriptions(string $list_id, string $cursor = NULL): object
    {
        $path = "lists/" . $list_id . "/subscriptions";

        if ($cursor) {
            $path = $path . "?cursor=" . $cursor;
        }

        return $this->doRequest(
            $this->buildRequest("get", $path)
        );
    }

    /**
     *  Subscribe multiple recipients to a list (note: if the List does not exist, it will be automatically created)
     *
     * @param string $list_id
     * @param array $recipients
     * @return object
     * @throws CourierRequestException
     */
    public function subscribeMultipleRecipientsToList(string $list_id, array $recipients): object
    {
        $params = array(
            'recipients' => $recipients
        );

        return $this->doRequest(
            $this->buildRequest("put", "lists/" . $list_id . "/subscriptions", $params)
        );
    }

    /**
     *  Subscribe a recipient to an existing list (note: if the List does not exist, it will be automatically created).
     *
     * @param string $list_id
     * @param string $recipient_id
     * @return object
     * @throws CourierRequestException
     */
    public function subscribeRecipientToList(string $list_id, string $recipient_id): object
    {
        return $this->doRequest(
            $this->buildRequest("put", "lists/" . $list_id . "/" . "subscriptions/" . $recipient_id)
        );
    }

    /**
     *  Delete a subscription to a list by list and recipient ID.
     *
     * @param string $list_id
     * @param string $recipient_id
     * @return object
     * @throws CourierRequestException
     */
    public function deleteListSubscription(string $list_id, string $recipient_id): object
    {
        return $this->doRequest(
            $this->buildRequest("delete", "lists/" . $list_id . "/" . "subscriptions/" . $recipient_id)
        );
    }

    /**
     *  Get the list of brands
     * @param string|null $cursor
     * @return object
     * @throws CourierRequestException
     */
    public function getBrands(string $cursor = NULL): object
    {
        $query_params = array(
            'cursor' => $cursor
        );

        return $this->doRequest(
            $this->buildRequest("get", "brands?" . http_build_query($query_params, null, '&', PHP_QUERY_RFC3986))
        );
    }

    /**
     * Create a new brand
     *
     * @param string|null $id
     * @param string $name
     * @param object $settings
     * @param object|null $snippets
     * @param string|null $idempotency_key
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function createBrand(string $id = NULL, string $name, object $settings, object $snippets = NULL, string $idempotency_key = NULL): object
    {
        $params = array(
            'id' => $id,
            'name' => $name,
            'settings' => $settings,
            'snippets' => $snippets
        );

        $params = array_filter($params);

        return $this->doRequest(
            $idempotency_key ? $this->buildIdempotentRequest("post", "brands", $params, $idempotency_key)
            : $this->buildRequest("post", "brands", $params)
        );
    }

    /**
     *  Fetch a specific brand by brand ID.
     *
     * @param string $brand_id
     * @return object
     * @throws CourierRequestException
     */
    public function getBrand(string $brand_id): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "brands/" . $brand_id)
        );
    }

    /**
     *  Replace an existing brand with the supplied values.
     *
     * @param string $brand_id
     * @param string $name
     * @param object $settings
     * @param object|null $snippets
     * @return object
     * @throws CourierRequestException
     */
    public function replaceBrand(string $brand_id, string $name, object $settings, object $snippets = NULL): object
    {
        $params = array(
            'name' => $name,
            'settings' => $settings,
            'snippets' => $snippets
        );

        $params = array_filter($params);

        return $this->doRequest(
            $this->buildRequest("put", "brands/" . $brand_id, $params)
        );
    }

    /**
     *  Delete a brand by brand ID.
     *
     * @param string $brand_id
     * @return object
     * @throws CourierRequestException
     */
    public function deleteBrand(string $brand_id): object
    {
        return $this->doRequest(
            $this->buildRequest("delete", "brands/" . $brand_id)
        );
    }

    /**
     *  Fetch the list of events
     * @return object
     * @throws CourierRequestException
     */
    public function getEvents(): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "events")
        );
    }

    /**
     *  Fetch a specific event by event ID.
     *
     * @param string $event_id
     * @return object
     * @throws CourierRequestException
     */
    public function getEvent(string $event_id): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "events/" . $event_id)
        );
    }

    /**
     *  Replace an existing event with the supplied values or create a new event if one does not already exist.
     *
     * @param string $event_id
     * @param string $id
     * @param string $type
     * @return object
     * @throws CourierRequestException
     */
    public function putEvent(string $event_id, string $id, string $type): object
    {
        $params = array(
            'id' => $id,
            'type' => $type
        );

        return $this->doRequest(
            $this->buildRequest("put", "events/" . $event_id, $params)
        );
    }

    /**
     *  Get the profile stored under the specified recipient ID.
     *
     * @param string $recipient_id
     * @return object
     * @throws CourierRequestException
     */
    public function getProfile(string $recipient_id): object
    {
        return $this->doRequest(
            $this->buildRequest("get", "profiles/" . $recipient_id)
        );
    }

    /**
     *  Merge the supplied values with an existing profile or
     *  create a new profile if one doesn't already exist.
     *
     * @param string $recipient_id
     * @param object $profile
     * @return object
     * @throws CourierRequestException
     */
    public function upsertProfile(string $recipient_id, object $profile = NULL): object
    {
        return $this->doRequest(
            $this->buildRequest("post", "profiles/" . $recipient_id, array('profile' => $profile))
        );
    }

    /**
     *  Apply a JSON Patch (RFC 6902) to the specified profile or
     *  create one if a profile doesn't already exist.
     *
     * @param string $recipient_id
     * @param array $patch
     * @return object
     * @throws CourierRequestException
     */
    public function patchProfile(string $recipient_id, array $patch): object
    {
        return $this->doRequest(
            $this->buildRequest("patch", "profiles/" . $recipient_id, array('patch' => $patch))
        );
    }

    /**
     *  Replace an existing profile with the supplied values or
     *  create a new profile if one does not already exist.
     *
     * @param string $recipient_id
     * @param object $profile
     * @return object
     * @throws CourierRequestException
     */
    public function replaceProfile(string $recipient_id, object $profile = NULL): object
    {
        return $this->doRequest(
            $this->buildRequest("put", "profiles/" . $recipient_id, array('profile' => $profile))
        );
    }

    /**
     *  Get the subscribed lists for a specified recipient Profile.
     *
     * @param string $recipient_id
     * @param string|null $cursor
     * @return object
     * @throws CourierRequestException
     */
    public function getProfileLists(string $recipient_id, string $cursor = NULL): object
    {
        $path = "profiles/" . $recipient_id . "/lists";

        if ($cursor) {
            $path = $path . "?cursor=" . $cursor;
        }

        return $this->doRequest(
            $this->buildRequest("get", $path)
        );
    }

    /**
     *  Replace an existing set of preferences with the supplied
     *  values or create a new set of preferences if they do not already exist.
     *
     * @param string $recipient_id
     * @param string $preferred_channel
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getPreferences(string $recipient_id, string $preferred_channel): object
    {

        return $this->doRequest(
            $this->buildRequest("get", "preferences/" . $recipient_id, array('preferred_channel' => $preferred_channel))
        );
    }

    /**
     *  Replace an existing set of preferences with the supplied
     *  values or create a new set of preferences if they do not already exist.
     *
     * @param string $recipient_id
     * @param string $preferred_channel
     * @return object
     * @throws CourierRequestException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function updatePreferences(string $recipient_id, string $preferred_channel): object
    {

        return $this->doRequest(
            $this->buildRequest("put", "preferences/" . $recipient_id, array('preferred_channel' => $preferred_channel))
        );
    }

}
