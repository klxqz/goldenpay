<?php

/**
 *
 * @author wa-plugins.ru
 * @name GoldenPay
 * @description GoldenPay Payments
 *
 * @property-read string $userName
 * @property-read string $password
 */
class goldenpayPayment extends waPayment implements waIPayment {

    private $url = 'http://66.135.38.46:80/ecomm/getmoney';
    private $server = 'http://66.135.38.46:80/';
    private $trans_id;
    private $currency = array(
        'AZN',
    );

    public function allowedCurrency() {
        return $this->currency;
    }

    private function getEndpointUrl() {
        return $this->url;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false) {
        $order = waOrder::factory($order_data);
        $view = wa()->getView();
        $view->assign('data', $payment_form_data);
        $view->assign('order', $order_data);
        $view->assign('settings', $this->getSettings());
        $form = array();

        $params = base64_encode(json_encode(array('app_id' => $this->app_id, 'merchant_id' => $this->merchant_id, 'order_id' => $order_data['order_id'])));

        $ct = 'v'; //cardtype

        $form['m'] = $this->userName . $ct;
        $form['amount'] = $order->total * 100;
        $form['desc'] = $params;
        $form['lang'] = $this->lang;
        $form['cs'] = md5($form['m'] . ($form['amount']) . $form['desc'] . $this->password);

        $view->assign('form', $form);
        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request) {
        if (!empty($request['trans_id'])) {
            try {
                $wamodel = new waModel();
                $sql = "SELECT * FROM `shop_plugin` WHERE `plugin`='goldenpay'";
                $plugin = $wamodel->query($sql)->fetch();
                if ($plugin) {
                    $plugin_id = $plugin['id'];
                    $sql = "SELECT * FROM `shop_plugin_settings` WHERE `id`='$plugin_id' AND `name`='userName'";
                    $result = $wamodel->query($sql)->fetch();
                    if ($result) {
                        $userName = $result['value'];

                        $url = $this->server . 'ecomm/getstat';
                        $params = array(
                            'm' => $userName . $request['m'],
                            'transId' => str_replace('+', "%2b", $request['trans_id'])
                        );
                        $response = $this->sendData($url, $params);
                        $transaction_data = $this->formalizeData($response);
                        $this->app_id = $transaction_data['app_id'];
                        $this->merchant_id = $transaction_data['merchant_id'];
                    }
                }
            } catch (Exception $e) {
                //$error = $e->getMessage();
            }
            $this->trans_id = $request['trans_id'];
        } elseif (!empty($request['app_id'])) {
            $this->app_id = $request['app_id'];
        }

        return parent::callbackInit($request);
    }

    protected function callbackHandler($request) {

        if (!$this->trans_id) {
            throw new waPaymentException('Ошибка. Не задан идентификатор транзакции');
        }

        $url = $this->server . 'ecomm/getstat';

        $params = array(
            'm' => $this->userName . $request['m'],
            'transId' => str_replace('+', "%2b", $request['trans_id'])
        );
        $request = $this->sendData($url, $params);
        $transaction_data = $this->formalizeData($request);

        if ($transaction_data['success'] == "1") {
            $message = "Оплата прошла успешно";
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['state'] = self::STATE_CAPTURED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        } else {
            $message = "Оплата прошла с ошибкой";
            $app_payment_method = self::CALLBACK_DECLINE;
            $transaction_data['state'] = self::STATE_DECLINED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
        }

        $transaction_data = $this->saveTransaction($transaction_data, $request);
        $result = $this->execAppCallback($app_payment_method, $transaction_data);
        self::addTransactionData($transaction_data['id'], $result);

        return array(
            'template' => $this->path . '/templates/callback.html',
            'back_url' => $url,
            'message' => $message,
        );
    }

    private function sendData($url, $data) {

        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new waException('PHP расширение cURL не доступно');
        }

        if (!($ch = curl_init())) {
            throw new waException('curl init error');
        }

        if (curl_errno($ch) != 0) {
            throw new waException('Ошибка инициализации curl: ' . curl_errno($ch));
        }

        $postdata = array();

        foreach ($data as $name => $value) {
            $postdata[] = "$name=$value";
        }

        $post = implode('&', $postdata);

        @curl_setopt($ch, CURLOPT_URL, $url);
        @curl_setopt($ch, CURLOPT_POST, 1);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        @curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        @curl_setopt($ch, CURLE_OPERATION_TIMEOUTED, 120);

        $response = @curl_exec($ch);
        $app_error = null;
        if (curl_errno($ch) != 0) {
            $app_error = 'Ошибка curl: ' . curl_errno($ch);
        }
        curl_close($ch);
        if ($app_error) {
            throw new waException($app_error);
        }
        if (empty($response)) {
            throw new waException('Пустой ответ от сервера');
        }

        return $response;
    }

    protected function formalizeData($transaction_raw_data) {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $dom = new DOMDocument("1.0", "UTF-8");
        $dom->encoding = 'UTF-8';
        $dom->loadXML($transaction_raw_data);

        $transaction_data['trans_id'] = @$dom->getElementsByTagName('trans_id')->item(0)->nodeValue;
        $transaction_data['amount'] = @$dom->getElementsByTagName('amount')->item(0)->nodeValue;
        $transaction_data['description'] = @$dom->getElementsByTagName('description')->item(0)->nodeValue;
        $transaction_data['success'] = @$dom->getElementsByTagName('success')->item(0)->nodeValue;
        $transaction_data['checked'] = @$dom->getElementsByTagName('checked')->item(0)->nodeValue;

        $params = json_decode(base64_decode($transaction_data['description']), true);
        $transaction_data['native_id'] = $params['order_id'];
        $transaction_data['order_id'] = $params['order_id'];
        $transaction_data['app_id'] = $params['app_id'];
        $transaction_data['merchant_id'] = $params['merchant_id'];

        return $transaction_data;
    }

}
