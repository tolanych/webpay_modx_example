<?php

define('MODX_API_MODE', true);
/** @noinspection PhpIncludeInspection */
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/index.php';
require_once 'b24_crm.php';
require_once 'webpay.class.php';

$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

// Switch context
$context = 'web';
$modx->switchContext($context);

define('MODX_ACTION_MODE', true);

$response = [];
$WebPayHandler = new WebPayPayment($modx);
$WebPayHandler->log('ALL | ', json_encode($_REQUEST) . json_encode($_SERVER));

// выставление счета и редирект на Webpay
if ($_REQUEST['action'] == 'order') {
    do {
        if (empty($modx->user->id)) {
            die('Access denied');
        }
        $find_order = EPfindOrder($_REQUEST['EP_OrderNo']);

        //если не найдено - выкидываем
        if (!$find_order) {
            header("HTTP/1.0 400 Bad Request");
            print $status = 'FAILED | не найден номер заказа';
            break;
        }

        // проверяем, что по статусам
        $state = $find_order->getTVValue('paymentState');
        switch ($state) {
            case 0:
                //всё ок
                $find_order->setTVValue('paymentState', '1');
                $find_order->setTVValue('paymentStateDate', date("Y-m-d H:i:s"));
                $find_order->setTVValue('paymentForm', ($_REQUEST['EP_PayType'] == 'PT_ERIP') ? 'PT_ERIP' : 'PT_CARD');
                $paymentNumber = $find_order->get('id');
                $find_order->setTVValue('paymentNumber', $paymentNumber);
                $_REQUEST['EP_OrderNo'] = $paymentNumber;
                break;
            case 1:
                //счет уже выставлен
                if ($find_order->getTVValue('paymentForm') == 'PT_ERIP') {
                    if ($find_order->getTVValue('paymentNumberErip') > 0) {
                        $status = 'ALREADY | ERIP - счет уже есть';
                        break 2;
                    }
                } else {
                    $paymentNumber = $find_order->get('id');
                    $find_order->setTVValue('paymentNumber', $paymentNumber);
                    $find_order->setTVValue('paymentForm', ($_REQUEST['EP_PayType'] == 'PT_ERIP') ? 'PT_ERIP' : 'PT_CARD');
                    $_REQUEST['EP_OrderNo'] = $paymentNumber;
                }
                break;
            case 2:
                //счет уже оплачен
                header("HTTP/1.0 400 Bad Request");
                $status = 'FAILED | счет уже оплачен ';
                break 2;
            case 3:
                //счет просрочен, завести новый (?)
                $status = 'FAILED | счет просрочен';
                break 2;
        }

        $cacheKey = $find_order->getCacheKey();
        $modx->cacheManager->refresh(array(
            'resource' => array('key' => $cacheKey),
        ));

        // если были какие-то ошибки, уходим
        if (!empty($status)) {
            break;
        }

        // fiz/ur
        $price = $modx->getOption('BXA_TARIF_' . $find_order->getTVValue(42));

        if ($_REQUEST['EP_PayType'] == 'PT_ERIP') {
            //API ERIP
            $data = [
                "resourceId" => $modx->getOption('webpay.store_id'),
                "resourceOrderNumber" => $_REQUEST['EP_OrderNo'],
                "items" => [
                    [
                        "idx" => 1,
                        "name" => "оплата счета " . $_REQUEST['EP_OrderNo'],
                        "quantity" => 1,
                        "price" => [
                            "currency" => "BYN",
                            "amount" => number_format($price, 2, '.', ''),
                        ],
                    ],
                ],
                "total" => [
                    "currency" => "BYN",
                    "amount" => number_format($price, 2, '.', ''),
                ],
                "urls" => [
                    "resourceReturnUrl" => $modx->makeUrl($find_order->get('id'), '', array('action' => 'recieve', 'id_order' => $_REQUEST['EP_OrderNo']), 'full'),
                    "resourceCancelUrl" => $modx->makeUrl($find_order->get('id'), '', array('action' => 'cancel'), 'full'),
                    "resourceNotifyUrl" => $modx->getOption('webpay.url_notify') . '?action=notify&id_order=' . $_REQUEST['EP_OrderNo'],
                ],
            ];
            $response = $WebPayHandler->APIorderERIP($data);
            if (!empty($response['resourceOrderNumber'])) {
                $find_order->setTVValue('paymentNumberErip', $response['resourceOrderNumber']);
            }
            $response['success'] = 1;
            $response['redirect'] = $modx->makeUrl($find_order->get('id'), '', '', 'https');
        } else {
            //карта

            $link = $modx->makeUrl($find_order->get('id'), '', '', 'https');

            $webpay_args = array(
                'order_num' => $_REQUEST['EP_OrderNo'],
                'curr_id' => 'BYN',
                'seed' => time(),
                'wsb_total' => number_format($price, 2, '.', ''),
                'name' => "оплата счета " . $_REQUEST['EP_OrderNo'],
                'quantity' => 1,
                'price' => number_format($price, 2, '.', ''),
                'wsb_notify_url' => $modx->getOption('webpay.url_notify') . '?action=notify&id_order=' . $_REQUEST['EP_OrderNo'],
                'wsb_return_url' => $modx->makeUrl($find_order->get('id'), '', array('action' => 'recieve', 'id_order' => $_REQUEST['EP_OrderNo']), 'full'),
                'wsb_cancel_return_url' => $modx->makeUrl($find_order->get('id'), '', array('action' => 'cancel'), 'full'),
            );
            $WebPayHandler->log('TRYING | отправка на WebPay Card', json_encode($webpay_args));
            $response = $WebPayHandler->orderCard($webpay_args);
            $response['success'] = 1;
        }
    } while (false);

    if (!empty($status)) {
        $WebPayHandler->log($status, json_encode($_REQUEST));
        if ($find_order->get('id')) {
            $link = $modx->makeUrl($find_order->get('id'), '', '', 'https');
        } else {
            $link = $modx->makeUrl(1867, '', '', 'https');
        }
        $response['success'] = 0;
        $response['message'] = $status;
        $response['redirect'] = $link;
    }
}

// шаг обработка уведомления об оплате
if ($_GET['action'] == 'notify' && !empty($_POST['wsb_signature'])) {
    do {
        $WebPayHandler->log('WEBPAY NOTIFY | ', json_encode($_REQUEST));
        $hash = $WebPayHandler->checkSignature($_POST);

        if ($hash != $_POST['wsb_signature']) {
            //order_notify_hash_error
            header("HTTP/1.0 400 Bad Request");
            $WebPayHandler->log('FAILED | WSB_SIGNATURE', json_encode($_REQUEST));
            break;
        }

        $find_order = EPfindOrder($_POST['site_order_id']);
        if (!$find_order) {
            header("HTTP/1.0 400 Bad Request");
            $WebPayHandler->log('FAILED | не найден номер заказа', json_encode($_REQUEST));
            break;
        }

        if (in_array($_POST['payment_type'], [1, 4, 10])) {
            // Изменение статуса заказа на оплачен
            //1 Completed
            //4 Authorized
            //10 Recurrent
            $find_order->setTVValue('paymentState', '2');
            $find_order->setTVValue('paymentStateDateEnd', date("Y-m-d H:i:s"));

            //отправка в B24 CRM
            $fields = array("STATUS_ID" => "6");
            $bx_res = BX_updateLeadbyId($find_order->getTVValue('crmOrderId'), $fields);
            $_REQUEST['BX_response'] = json_decode($bx_res, true);
        } else {
            $WebPayHandler->log('FAILED | заказ не оплачен', json_encode($_REQUEST));
        }

        //очистка кеша ресурса
        $cacheKey = $find_order->getCacheKey();
        $modx->cacheManager->refresh(array(
            'resource' => array('key' => $cacheKey),
        ));
        header("HTTP/1.0 200 OK");
        exit();
    } while (false);
}

// поиск заказа по номеру
function EPfindOrder($orderNo)
{
    global $modx;
    $find_order = null;
    $parents = $modx->getCollection('modResource', array(
        'parent' => 1862,
        'id' => $orderNo,
    ));
    foreach ($parents as $res) {
        $find_order = $res;
    }
    return $find_order;
}

if (is_array($response)) {
    $response = json_encode($response);
}

@session_write_close();
exit($response);
