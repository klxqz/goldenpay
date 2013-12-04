<?php

/**
 *
 * @author wa-plugins.ru
 * @name GoldenPay
 * @description GoldenPay Payments
 *
 * @property-read string $userName
 * @property-read string $password
 * @property-read string $sandbox
 */
class goldenpayPayment extends waPayment implements waIPayment {

    private $url = 'http://66.135.38.46:80/ecomm/getmoney';
    private $order_id;
    private $currency = array(
        '840' => 'USD',
        '980' => 'UAH',
        '810' => 'RUB',
        '946' => 'RON',
        '398' => 'KZT',
        '417' => 'KGS',
        '392' => 'JPY',
        '826' => 'GBR',
        '978' => 'EUR',
        '156' => 'CNY',
        '974' => 'BYR',
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

        $ct = 'v'; //cardtype

        $form['m'] = $this->userName . $ct;
        $form['amount'] = $order->total * 100;
        $form['desc'] = "";
        $form['lang'] = "lv";
        $form['cs'] = md5($form['m'] . ($form['amount']) . $form['desc'] . $this->password);

        $view->assign('form', $form);
        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request) {
        if (!empty($request['orderId'])) {

            $params = json_decode(base64_decode($request['params']), true);
            $this->app_id = $params['app_id'];
            $this->merchant_id = $params['merchant_id'];
            $this->order_id = $request['orderId'];
        } elseif (!empty($request['app_id'])) {
            $this->app_id = $request['app_id'];
        }

        return parent::callbackInit($request);
    }

    protected function callbackHandler($request) {

        if (!$this->order_id) {
            throw new waPaymentException('Ошибка. Не верный номер заказа');
        }

        if ($this->sandbox) {
            $url = $this->test_url . 'getOrderStatus.do';
        } else {
            $url = $this->url . 'getOrderStatus.do';
        }


        $params = array(
            'userName' => $this->userName,
            'password' => $this->password,
            'orderId' => $this->order_id,
        );
        $request = $this->sendData($url, $params);
        $transaction_data = $this->formalizeData($request);


        if ($request['ErrorCode'] == 0 && $request['OrderStatus'] == 2) {
            $message = $request['ErrorMessage'];
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['state'] = self::STATE_CAPTURED;
            $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
        } else {
            switch ($request['ErrorCode']) {

                case 2:
                    $message = 'Заказ отклонен по причине ошибки в реквизитах платежа.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                case 5:
                    $message = 'Ошибка значения параметра запроса.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                case 6:
                    $message = 'Незарегистрированный OrderId.';
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
                default:
                    $message = $request['ErrorMessage'];
                    $app_payment_method = self::CALLBACK_DECLINE;
                    $transaction_data['state'] = self::STATE_DECLINED;
                    $url = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data);
                    break;
            }
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

        $json = json_decode($response, true);

        if (!is_array($json)) {
            throw new waException('Ошибка оплаты. ' . $response);
        }



        return $json;
    }

    protected function formalizeData($transaction_raw_data) {
        $currency_id = $transaction_raw_data['currency'];

        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = $this->order_id;
        $transaction_data['order_id'] = $transaction_raw_data['OrderNumber'];
        $transaction_data['currency_id'] = $this->currency[$currency_id];
        $transaction_data['amount'] = $transaction_raw_data['Amount'];
        //$transaction_data['view_data'] = 'view_data';


        return $transaction_data;
    }

}
