<?php

/**
 * @package
 * @author    Daan van den Bergh
 *            https://woosh.dev
 *            https://daan.dev
 * @copyright © 2020 Daan van den Bergh
 * @license   BY-NC-ND-4.0
 *            http://creativecommons.org/licenses/by-nc-nd/4.0/
 */

defined('ABSPATH') || exit;

class MoneybirdClient
{
    const CONTENT_TYPE_JSON       = 'json'; // JSON format
    const CONTENT_TYPE_URLENCODED = 'urlencoded'; // urlencoded query string, like name1=value1&name2=value2
    const CONTENT_TYPE_XML        = 'xml'; // XML format
    const CONTENT_TYPE_AUTO       = 'auto'; // attempts to determine format automatically

    /**
     * @var array cURL request options. Option values from this field will overwrite corresponding
     * values from [[defaultCurlOptions()]].
     */
    private $_curlOptions = [];

    /** @var string API base URL. */
    private $apiBaseUrl = 'https://moneybird.com';

    /** @var string authorize URL. */
    private $authUrl = 'https://moneybird.com/oauth/authorize';

    /** @var string the api base url, e.g. "https://moneybird.com/api/" */
    protected $apiUrl = "https://moneybird.com/api/";

    /** @var string the version of the API to be used, at the moment Moneybird only supports v2 */
    protected $version = "v2";

    /** @var string token request URL endpoint. */
    private $tokenUrl = 'https://moneybird.com/oauth/token';

    /** @var array client_id, client_secret, admin_name, admin_id, redirect_uri, authorization_code, access_token, refresh_token */
    private $clientData;

    /**
     * @param $clientData array client_id, client_secret, admin_name, admin_id, redirect_uri, authorization_code, access_token, refresh_token
     *
     * @throws Exception when the tokens are not set or when authentication fails
     */
    public function __construct($clientData)
    {
        if (empty($clientData['client_id']) || empty($clientData['client_id'])) {
            throw new Exception('An ClientID and Client secret token is not provided, this is required when using MoneyBirdClient');
        }
        $this->clientData = $clientData;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function init()
    {
        try {
            if (empty($this->clientData['authorization_code'])) {
                error_log("authorization code empty, retrieve new");
                if (isset($_GET['code'])) {
                    $nonce = $_REQUEST['mb_oauth2'];

                    if (strpos($this->clientData['auth_url'], $nonce) === false) {
                        error_log("nonce error");
                        throw new Exception('Nonce error!');
                    } else {
                        error_log("updating authorization code with " . $_GET['code']);
                        update_option('mb_authorization_code', $_GET['code']);
                        wp_redirect($this->clientData['redirect_uri2']);
                        exit;
                    }
                } else {
                    if (!isset($this->clientData['redirect_uri']) || empty($this->clientData['redirect_uri'])) {
                        $this->clientData['redirect_uri'] = wp_nonce_url(get_site_url(), -1, 'mb_oauth2');
                    }
                    $url                          = $this->authUrl . '?client_id=' . $this->clientData['client_id'] . '&redirect_uri=' . $this->clientData['redirect_uri'] . '&response_type=code&scope=sales_invoices%20bank';
                    $this->clientData['auth_url'] = $url;
                    add_action(
                        'admin_notices',
                        array(
                            $this,
                            'admin_notice_getAuthorizationCode'
                        )
                    );
                }
            } else {
                // error_log("authorization code found");
                try {
                    if (empty($this->clientData['access_token'])) {
                        $this->get_mb_access_token();
                    } else {
                        $this->check_connection();
                    }
                } catch (Exception $e) {
                    // $this->refresh_mb_access_token();
                    throw $e;
                }
            }
        } catch (Exception $e) {
            _log($e);
            throw $e;
        }

        return $this->clientData;
    }

    /**
     * Check if connection to MB is still valid, and if so set the admin name and id
     *
     * @since  1.0
     *
     * @access private
     *
     */

    private function check_connection()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/administrations.json');

            if (!isset($sendRequestResult['error'])) {
                $this->clientData['admin_name'] = $sendRequestResult[0]['name'];
                $this->clientData['admin_id']   = $sendRequestResult[0]['id'];
            } else {
                throw new Exception('Error in creating connection: ' . $sendRequestResult['error']);
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Get the access token
     *
     * @since  1.0
     *
     * @access private
     *
     */

    private function get_mb_access_token()
    {
        $params = array(
            'client_id'     => $this->clientData['client_id'],
            'client_secret' => $this->clientData['client_secret'],
            'code'          => $this->clientData['authorization_code'],
            'redirect_uri'  => $this->clientData['redirect_uri'],
            'grant_type'    => 'authorization_code'
        );
        try {
            $sendRequestResult = $this->send_request('POST', $this->tokenUrl, $params);

            foreach ($sendRequestResult as $key => $value) {
                $this->clientData[$key] = $value;
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $invoice_id
     *
     * @return array
     * @throws Exception
     */
    public function get_invoice($invoice_id)
    {
        $ret_val = array();

        try {
            $ret_val = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $invoice_id . '.json');
        } catch (Exception $e) {
            throw $e;
        }

        return $ret_val;
    }

    /**
     * @param $mb_naw
     *
     * @return bool|mixed|string
     */
    public function create_contact($mb_naw)
    {

        try {
            $params = array('JSON' => '{"contact":' . json_encode($mb_naw) . '}');
            _log("Create new contact");
            _log($params);
            $sendRequestResult = $this->send_request('POST', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/contacts.json', $params);
            _log($sendRequestResult);

            if (isset($sendRequestResult["error"])) {
                return false;
            } elseif (isset($sendRequestResult["id"])) {
                return $sendRequestResult["id"];
            } else {
                return "failure_retrieving";
            }
        } catch (Exception $e) {
            $error = json_decode($e);
            _log($error);
        }
    }

    /**
     * @param      $mb_naw
     * @param bool $mb_contact_id
     *
     * @return bool|int
     * @throws Exception
     */
    public function update_contact($mb_naw, $mb_contact_id = false)
    {
        //don't set this on updates
        //unset($mb_naw["customer_id"]);
        //retrieve mb id
        if (!$mb_contact_id) {
            $mb_contact_id = $this->get_contact($mb_naw['send_invoices_to_email']);
        }

        try {
            $params = array('JSON' => '{"contact":' . json_encode($mb_naw) . '}');
            _log("Before update contact");
            $sendRequestResult = $this->send_request('PATCH', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/contacts/' . $mb_contact_id . '.json', $params);
        } catch (Exception $e) {
            _log($e);
            throw $e;
        }

        if (isset($sendRequestResult["id"])) {
            return intval($sendRequestResult["id"]);
        } else {
            return false;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_custom_fields()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/custom_fields.json');
            $custom_fields     = array_column($sendRequestResult, 'name', 'id');

            return $custom_fields;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $email
     *
     * @return bool|int
     */
    public function get_contact($email)
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/contacts.json?query=' . $email);
            _log("contact search output");
            _log($sendRequestResult);
        } catch (Exception $e) {
            _log($e);
        }
        if (isset($sendRequestResult["error"])) {
            return false;
        } elseif (isset($sendRequestResult[0]) && isset($sendRequestResult[0]["id"]) && (strlen($sendRequestResult[0]["id"]) > 0)) {
            return intval($sendRequestResult[0]["id"]);
        } else {
            return false;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_financial_accounts()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/financial_accounts.json');
            error_log("Get financial accounts");
            _log($sendRequestResult);

            return $sendRequestResult;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $financial_statement
     *
     * @return array
     * @throws Exception
     */
    public function add_financial_statement($financial_statement)
    {

        try {

            $params = array('JSON' => '{"financial_statement":' . json_encode($financial_statement, JSON_FORCE_OBJECT) . '}');

            $response = $this->send_request('POST', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/financial_statements.json', $params);

            return $response;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_financial_mutations()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/financial_mutations.json?filter=period%3Athis_year%2Cstate%3Aunprocessed');

            return $sendRequestResult;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $sales_invoice_id
     * @param $financial_account_id
     * @param $financial_mutation_id
     * @param $payment
     *
     * @throws Exception
     */
    public function create_payment($sales_invoice_id, $transaction_id, $payment)
    {
        try {
            $payment = array(
                'payment_date'           => $payment->completed_date,
                'price'                  => $payment->total,
                'price_base'             => $payment->total,
                'transaction_identifier' => $transaction_id
            );

            $params = array('JSON' => '{"payment":' . json_encode($payment) . '}');

            $this->send_request('POST', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $sales_invoice_id . '/payments.json', $params);
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_all_contacts()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/contacts.json');

            return $sendRequestResult;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $sales_invoice
     * @param $delivery_method
     * @param $ubl
     *
     * @return bool|int
     * @throws Exception
     */
    public function create_invoice($sales_invoice, $delivery_method, $ubl)
    {

        try {
            $invoiceID           = false;
            $params              = array('JSON' => '{"sales_invoice":' . json_encode($sales_invoice) . '}');
            $createInvoiceResult = $this->send_request('POST', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices.json', $params);

            if (isset($createInvoiceResult['id'])) {
                $invoiceID = intval($createInvoiceResult['id']);
            }

            //with method concept, we leave it as concept here.

            if ($delivery_method != "Concept") {
                $send_attributes = array(
                    //'administration_id' => $this->clientData['admin_id'],
                    'delivery_method'   => $delivery_method,
                    'sending_scheduled' => false,
                    'deliver_ubl'       => $ubl,
                    'mergeable'         => false,
                    'email_address'     => $sales_invoice["email_address"],
                    'invoice_date'      => $sales_invoice['invoice_date'],
                );

                $params            = array('JSON' => '{"sales_invoice_sending":' . json_encode($send_attributes) . '}');
                $sendRequestResult = $this->send_request('PATCH', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $invoiceID . '/send_invoice.json', $params);
            }
            if ($invoiceID) {
                return $invoiceID;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $mb_invoice_id
     * @param $delivery_method
     * @param $ubl
     * @param $refund_transaction_id
     *
     * @return bool|int
     * @throws Exception
     */
    public function create_credit_invoice($mb_invoice_id, $delivery_method, $ubl, $refund_transaction_id)
    {

        try {
            //then, duplicate to credit invoice
            //$params = array('JSON' => '{"sales_invoice":'.json_encode($sales_invoice).'}');
            $createInvoiceResult = $this->send_request('PATCH', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $mb_invoice_id . '/duplicate_creditinvoice.json');

            $invoiceID = intval($createInvoiceResult['id']);

            //could be used to update reference
            if ($refund_transaction_id) {
                $update_reference_attributes = array(
                    'reference' => $refund_transaction_id,
                );
                $params                      = array('JSON' => '{"sales_invoice":' . json_encode($update_reference_attributes) . '}');
                $sendRequestResult           = $this->send_request('PATCH', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $invoiceID . '.json', $params);
            }

            //with method concept, we leave it as concept here.
            if ($delivery_method != "Concept") {
                $send_attributes = array(
                    //'administration_id' => $this->clientData['admin_id'],
                    'delivery_method'   => $delivery_method,
                    'sending_scheduled' => false,
                    'deliver_ubl'       => $ubl,
                    'mergeable'         => false,
                );

                $params            = array('JSON' => '{"sales_invoice_sending":' . json_encode($send_attributes) . '}');
                $sendRequestResult = $this->send_request('PATCH', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/sales_invoices/' . $invoiceID . '/send_invoice.json', $params);
            }
            if (!isset($sendRequestResult["error"])) {
                return $invoiceID;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_workflows()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/workflows.json');
            $workflows         = array();
            foreach ($sendRequestResult as $value) {
                if (isset($value['type']) && ($value['type'] == 'InvoiceWorkflow')) {
                    $workflows[$value['id']] = $value['name'];
                }
            }

            return $workflows;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_document_styles()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/document_styles.json');
            $document_styles   = array_column($sendRequestResult, 'name', 'id');

            return $document_styles;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_tax_rates()
    {
        try {
            $tax_rates         = array();
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/tax_rates.json');
            foreach ($sendRequestResult as $tax_rate) {
                if (isset($tax_rate["active"]) && $tax_rate["active"] && ($tax_rate["tax_rate_type"] == "sales_invoice")) {
                    $tax_rates[$tax_rate['id']] = $tax_rate["name"];
                }
            }

            return $tax_rates;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_products()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/products.json');
            $products          = array_column($sendRequestResult, 'description', 'id');

            return $products;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get_categories()
    {
        try {
            $sendRequestResult = $this->send_request('GET', $this->apiUrl . $this->version . '/' . $this->clientData['admin_id'] . '/ledger_accounts.json');
            $categories        = array();
            foreach ($sendRequestResult as $categorie) {
                if (isset($categorie['account_type']) && ($categorie['account_type'] == 'revenue')) {
                    $categories[$categorie['id']] = $categorie["name"];
                }
            }

            return $categories;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Adds the API token authorization header to the set of headers provided
     *
     * @param array $headers the headers already provided
     *
     * @return array $headers the original headers with the authorization header added
     */
    protected function add_authorization_header(array $headers)
    {
        if (!empty($this->clientData['access_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->clientData['access_token'];
        }

        return $headers;
    }

    /**
     * Performs request to the OAuth API.
     *
     * @param string $apiSubUrl API sub URL, which will be append to [[apiBaseUrl]], or absolute API URL.
     * @param string $method    request method.
     * @param array  $params    request parameters.
     * @param array  $headers   additional request headers.
     *
     * @return array API response
     */
    public function api($apiSubUrl, $method = 'GET', array $params = [], array $headers = [])
    {
        if (preg_match('/^https?:\\/\\//is', $apiSubUrl)) {
            $url = $apiSubUrl;
        } else {
            $url = $this->apiBaseUrl . '/' . $apiSubUrl;
        }

        return $this->apiInternal($url, $method, $params, $headers);
    }

    /**
     * @inheritdoc
     */
    protected function apiInternal($url, $method, array $params, array $headers)
    {
        return $this->send_request($method, $url, $params, $headers);
    }

    /**
     * Composes HTTP request CUrl options, which will be merged with the default ones.
     *
     * @param string $method request type.
     * @param string $url    request URL.
     * @param array  $params request params.
     *
     * @return array CUrl options.
     * @throws Exception on failure.
     */
    protected function composeRequestCurlOptions($method, $url, array $params)
    {
        $curlOptions = [];
        switch ($method) {
            case 'GET': {
                    $curlOptions[CURLOPT_URL] = $this->composeUrl($url, $params);
                    break;
                }
            case 'POST': {
                    $curlOptions[CURLOPT_POST] = true;
                    if (empty($params['JSON'])) {
                        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-type: application/x-www-form-urlencoded'];
                        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                    } else {
                        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
                        $curlOptions[CURLOPT_POSTFIELDS] = $params['JSON'];
                    }
                    break;
                }
            case 'PATCH': {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;;
                    if (empty($params['JSON'])) {
                        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-type: application/x-www-form-urlencoded'];
                        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                    } else {
                        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
                        $curlOptions[CURLOPT_POSTFIELDS] = $params['JSON'];
                    }
                    break;
                }
            case 'HEAD': {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
                    if (!empty($params)) {
                        $curlOptions[CURLOPT_URL] = $this->composeUrl($url, $params);
                    }
                    break;
                }
            default: {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
                    if (!empty($params)) {
                        $curlOptions[CURLOPT_POSTFIELDS] = $params;
                    }
                }
        }

        return $curlOptions;
    }

    /**
     * Composes URL from base URL and GET params.
     *
     * @param string $url    base URL.
     * @param array  $params GET params.
     *
     * @return string composed URL.
     */
    protected function composeUrl($url, array $params = [])
    {
        if (strpos($url, '?') === false) {
            $url .= '?';
        } else {
            $url .= '&';
        }
        $url .= http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $url;
    }

    /**
     * Converts XML document to array.
     *
     * @param string|\SimpleXMLElement $xml xml to process.
     *
     * @return array XML array representation.
     */
    protected function convertXmlToArray($xml)
    {
        if (!is_object($xml)) {
            $xml = simplexml_load_string($xml);
        }
        $result = (array) $xml;
        foreach ($result as $key => $value) {
            if (is_object($value)) {
                $result[$key] = $this->convertXmlToArray($value);
            }
        }

        return $result;
    }

    /**
     * Returns default cURL options.
     * @return array cURL options.
     */
    protected function defaultCurlOptions()
    {
        return [
            CURLOPT_USERAGENT      => 'MoneyBirdClient',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
    }

    /**
     * Attempts to determine HTTP request content type by headers.
     *
     * @param array $headers request headers.
     *
     * @return string content type.
     */
    protected function determineContentTypeByHeaders(array $headers)
    {
        if (isset($headers['content_type'])) {
            if (stripos($headers['content_type'], 'json') !== false) {
                return self::CONTENT_TYPE_JSON;
            }
            if (stripos($headers['content_type'], 'urlencoded') !== false) {
                return self::CONTENT_TYPE_URLENCODED;
            }
            if (stripos($headers['content_type'], 'xml') !== false) {
                return self::CONTENT_TYPE_XML;
            }
        }

        return self::CONTENT_TYPE_AUTO;
    }

    /**
     * Attempts to determine the content type from raw content.
     *
     * @param string $rawContent raw response content.
     *
     * @return string response type.
     */
    protected function determineContentTypeByRaw($rawContent)
    {
        if (preg_match('/^\\{.*\\}$/is', $rawContent)) {
            return self::CONTENT_TYPE_JSON;
        }
        if (preg_match('/^[^=|^&]+=[^=|^&]+(&[^=|^&]+=[^=|^&]+)*$/is', $rawContent)) {
            return self::CONTENT_TYPE_URLENCODED;
        }
        if (preg_match('/^<.*>$/is', $rawContent)) {
            return self::CONTENT_TYPE_XML;
        }

        return self::CONTENT_TYPE_AUTO;
    }

    /**
     * @return array cURL options.
     */
    public function getCurlOptions()
    {
        return $this->_curlOptions;
    }

    /**
     * @param array $curlOptions cURL options.
     */
    public function setCurlOptions(array $curlOptions)
    {
        $this->_curlOptions = $curlOptions;
    }

    /**
     * Merge CUrl options.
     * If each options array has an element with the same key value, the latter
     * will overwrite the former.
     *
     * @param array $options1 options to be merged to.
     * @param array $options2 options to be merged from. You can specify additional
     *                        arrays via third argument, fourth argument etc.
     *
     * @return array merged options (the original options are not changed.)
     */
    protected function mergeCurlOptions($options1, $options2)
    {
        $args = func_get_args();
        $res  = array_shift($args);
        while (!empty($args)) {
            $next = array_shift($args);
            foreach ($next as $k => $v) {
                if (is_array($v) && !empty($res[$k]) && is_array($res[$k])) {
                    $res[$k] = array_merge($res[$k], $v);
                } else {
                    $res[$k] = $v;
                }
            }
        }

        return $res;
    }

    /**
     * Processes raw response converting it to actual data.
     *
     * @param string $rawResponse raw response.
     * @param string $contentType response content type.
     *
     * @return array actual response.
     * @throws Exception on failure.
     */
    protected function process_response($rawResponse, $contentType = self::CONTENT_TYPE_AUTO)
    {
        if (empty($rawResponse)) {
            return [];
        }
        switch ($contentType) {
            case self::CONTENT_TYPE_AUTO: {
                    $contentType = $this->determineContentTypeByRaw($rawResponse);
                    if ($contentType == self::CONTENT_TYPE_AUTO) {
                        throw new Exception('Unable to determine response content type automatically.');
                    }
                    $response = $this->process_response($rawResponse, $contentType);
                    break;
                }
            case self::CONTENT_TYPE_JSON: {
                    $response = json_decode((string) $rawResponse, true);
                    break;
                }
            case self::CONTENT_TYPE_URLENCODED: {
                    $response = [];
                    parse_str($rawResponse, $response);
                    break;
                }
            case self::CONTENT_TYPE_XML: {
                    $response = $this->convertXmlToArray($rawResponse);
                    break;
                }
            default: {
                    throw new Exception('Unknown response type "' . $contentType . '".');
                }
        }

        return $response;
    }

    /**
     * Sends HTTP request.
     *
     * @param string $method  request type.
     * @param string $url     request URL.
     * @param array  $params  request params.
     * @param array  $headers additional request headers.
     *
     * @return array response.
     * @throws Exception on failure.
     */
    protected function send_request($method, $url, array $params = [], array $headers = [])
    {
        $curlOptions = $this->mergeCurlOptions(
            $this->defaultCurlOptions(),
            $this->getCurlOptions(),
            [
                CURLOPT_HTTPHEADER     => $this->add_authorization_header($headers),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_URL            => $url,
            ],
            $this->composeRequestCurlOptions(strtoupper($method), $url, $params)
        );

        $curlResource = curl_init();

        foreach ($curlOptions as $option => $value) {
            curl_setopt($curlResource, $option, $value);
        }

        $response = curl_exec($curlResource);

        $responseHeaders = curl_getinfo($curlResource);

        // check cURL error
        $errorNumber  = curl_errno($curlResource);
        $errorMessage = curl_error($curlResource);
        curl_close($curlResource);

        if ($errorNumber > 0) {
            throw new Exception('Curl error requesting "' . $url . '": #' . $errorNumber . ' - ' . $errorMessage);
        }
        if (strncmp($responseHeaders['http_code'], '20', 2) !== 0) {
            _log("request url " . $url);
            _log($responseHeaders['http_code']);
            throw new Exception('Request failed with code: ' . $responseHeaders['http_code'] . ', message: ' . $response);
        }

        return $this->process_response($response, $this->determineContentTypeByHeaders($responseHeaders));
    }
}
