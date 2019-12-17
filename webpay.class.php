<?php
/**
 * @author tolanych <tolanych@tut.by>
 * Handler class for webpay.by
 * 
 * Date: 17.12.2019
 */

class WebPayPayment
{
    public $modx;
    public $logfile = 'WEBPAY.log';
    public $url = '';
    public $APIurl = '';

    public function __construct($object)
    {
        $this->modx = &$object;
        $test = $this->modx->getOption('webpay.wsb_test');

        // different URLs for test/prod mode
        if ($test) {
            $this->url = 'https://securesandbox.webpay.by';
            $this->APIurl = 'https://sand-box.webpay.by/woc/order';
        } else {
            $this->url = 'https://payment.webpay.by';
            $this->APIurl = 'https://api.webpay.by/woc/order';
        }
    }

	/**
	 * Hash-function for sign request
	 *
	 * @param array $data
	 * @return string
	 */
    public function addSignature($data)
    {
        $secret_key = $this->modx->getOption('webpay.secret_key');
        $test = $this->modx->getOption('webpay.wsb_test');
        $store_id = $this->modx->getOption('webpay.store_id');
        $order_num = $data['order_num'];
        $curr_id = $data['curr_id'];
        $wsb_total = $data['wsb_total'];
        $seed = $data['seed'];

        $signature = $seed . $store_id . $order_num . $test . $curr_id . $wsb_total . $secret_key;
        $sha_signature = sha1($signature);

        return $sha_signature;
    }

	/**
	 * Hash-function for check response webpay
	 *
	 * @param array $data
	 * @return string
	 */
    public function checkSignature($data)
    {
        $hash = md5($data['batch_timestamp'] . $data['currency_id'] . $data['amount'] .
            $data['payment_method'] . $data['order_id'] . $data['site_order_id'] .
            $data['transaction_id'] . $data['payment_type'] . $data['rrn'] . $this->modx->getOption('webpay.secret_key'));
        return $hash;
    }

	/**
	 * Add Card Payment
	 * Prepare HTML hidden inputs and action URL for <FORM>
	 *
	 * @param array $data
	 * @return array
	 */
    public function orderCard($data)
    {

        $webpay_args = array(
            '*scart' => '',
            'wsb_version' => 2,
            'wsb_language_id' => "russian",
            'wsb_storeid' => $this->modx->getOption('webpay.store_id'),
            'wsb_order_num' => $data['order_num'],
            'wsb_test' => $this->modx->getOption('webpay.wsb_test'),
            'wsb_currency_id' => $data['curr_id'],
            'wsb_seed' => $data['seed'],
            'wsb_total' => $data['wsb_total'],
            'wsb_signature' => $this->addSignature($data),
            'wsb_invoice_item_name[]' => $data['name'],
            'wsb_invoice_item_quantity[]' => $data['quantity'],
            'wsb_invoice_item_price[]' => $data['price'],
            'wsb_due_date' => time() + (3600 * 48),
            'wsb_notify_url' => $data['wsb_notify_url'],
            'wsb_return_url' => $data['wsb_return_url'],
            'wsb_cancel_return_url' => $data['wsb_cancel_return_url'],
        );

        $webpay_args_array = array();
        foreach ($webpay_args as $key => $value) {
            $webpay_args_array[] = '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
        }

        $response = array(
            'fields' => implode("\n", $webpay_args_array),
            'redirect' => $this->url,
        );
        return $response;
    }

	/**
	 * Prepare sign request for API
	 *
	 * @param array $data
	 * @return string for HTTP Header Authorization
	 */
    public function APIaddSignature($data)
    {
        $nonce = time();
        $json_data = json_encode($data);
        $store_id = $this->modx->getOption('webpay.store_id');
        $secret_key = $this->modx->getOption('webpay.secret_key');

        $string_to_sign = implode("\n", [
            'POST',
            '/woc/order',
            'application/json;charset=utf-8',
            $store_id,
            $nonce,
            $json_data,
        ]);
        $string_to_sign = $string_to_sign . "\n";
        $digest = hash_hmac('sha512', $string_to_sign, $secret_key, true);
        $auth = 'HmacSHA512 ' . $store_id . ':' . $nonce . ':' . base64_encode($digest);

        return $auth;
    }

	/**
	 * Create order ERIP by API
	 *
	 * @param array $data
	 * @return array
	 */
    public function APIorderERIP($data)
    {
        $auth = $this->APIaddSignature($data);
        $json_data = json_encode($data);

        $request = curl_init();
        curl_setopt_array(
            $request,
            array(
                CURLOPT_URL => $this->APIurl,
                CURLOPT_HEADER => 0,
                CURLOPT_VERBOSE => 0,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => $json_data,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json;charset=utf-8',
                    'Authorization: ' . $auth,
                ),
            )
        );
        $response = curl_exec($request);
        $data_new = json_decode($response, true);
        curl_close($request);

        return $data_new;
    }

	/**
	 * Add log to file
	 *
	 * @param string $status
	 * @param string|array $request
	 * @return void
	 */
    public function log($status, $request)
    {
        $date = date("d.m.Y H:i:s");
        if (is_array($request)) {
            $request = json_encode($request);
        }
        $str = $date . "\t" . $status . "\n\t" . "ORDER_INFORMATION: " . $request . "\n";
        $log = fopen($this->logfile, 'a');
        if ($log) {
            fwrite($log, $str);
            fclose($log);
        }
    }
}
