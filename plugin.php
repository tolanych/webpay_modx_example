<?php
switch ($modx->event->name) {
    case 'OnDocFormSave':
        if ($resource->get('parent') == 1862 && $resource->class_key == "Ticket") {
            if (empty($resource->getTVValue(39))) {
                $id = $resource->get('id');
                $name = $modx->getObject('modUser', $resource->get('createdby'))->getOne('Profile')->fullname;
                $email = $modx->getObject('modUser', $resource->get('createdby'))->getOne('Profile')->email;
                $phone = $modx->getObject('modUser', $resource->get('createdby'))->getOne('Profile')->mobilephone;
                $extended = $modx->getObject('modUser', $resource->get('createdby'))->getOne('Profile')->get('extended');

                if (!empty($extended['unp'])) { //юр лицо
                    if (!empty(trim($_REQUEST['paymentTypeCompany']))) {
                        $resource->setTVValue(42, trim($_REQUEST['paymentTypeCompany']));
                        $price = trim($_REQUEST['paymentTypeCompany']) . 'c';
                    }
                    $STATUS_ID = "1";
                    $SOURCE_ID = 6;
                    switch ($price) {
                        case 2:
                            $title_pref = 'ЮР/ Стандарт /';
                            break;
                        case 3:
                            $title_pref = 'ЮР/ VIP /';
                            break;
                        case 4:
                            $title_pref = 'ЮР/ Консультация в офисе /';
                            break;
                        default:
                            $STATUS_ID = "NEW";
                            $title_pref = 'ЮР/ ';
                    }
                    if (!empty(trim($_REQUEST['QuestTypeCompany']))) {
                        $resource->setTVValue(47, trim($_REQUEST['QuestTypeCompany']));
                        $questype = trim($_REQUEST['QuestTypeCompany']);
                    }
                    $COMPANY_TITLE = $extended['companyname'] . '. УНП ' . $extended['unp'];
                } else { // физ лицо
                    $price = $resource->getTVValue(42);
                    $questype = $resource->getTVValue(47);
                    $STATUS_ID = "NEW";
                    $SOURCE_ID = 3;
                    switch ($price) {
                        case 2:
                            $title_pref = 'Эконом /';
                            break;
                        case 3:
                            $title_pref = 'Стандарт /';
                            break;
                        case 4:
                            $title_pref = 'VIP /';
                            break;
                        default:
                            $title_pref = '';
                    }
                }

                $resource->setTVValue(46, $result['result']);
                $resource->setTVValue(39, $id);

                $title = "$title_pref Заявка #$id";
                $content = $questype . "\n\n" . $resource->get('content');

                if (!empty($extended['unp'])) {
                    $content .= "\n\n\nУНП:" . $extended['unp'];
                }
                $link = "https://sitename/manager/index.php?a=resource/update&id=$id";

                $queryUrl = $modx->getOption('BX_CRM_path') . '/crm.lead.add.json';
                $queryData = http_build_query(array(
                    'fields' => array(
                        "UF_CRM_1545068678" => $content,
                        "UF_CRM_1545068711" => $link,
                        "TITLE" => $title,
                        "NAME" => $name,
                        "SOURCE_ID" => $SOURCE_ID,
                        "STATUS_ID" => $STATUS_ID,
                        "OPENED" => "Y",
                        "ASSIGNED_BY_ID" => 33,
                        "COMPANY_TITLE" => $COMPANY_TITLE,
                        "CURRENCY_ID" => 'BYN',
                        "OPPORTUNITY" => $modx->getOption('BXA_TARIF_' . $price),
                        "EMAIL" => array(
                            array("VALUE" => $email, "VALUE_TYPE" => "OTHER")),
                        "PHONE" => array(
                            array("VALUE" => $phone, "VALUE_TYPE" => "OTHER")),
                    ),
                    'params' => array(
                        "REGISTER_SONET_EVENT" => "Y"),
                )
                );

                $curl = curl_init();
                curl_setopt_array(
                    $curl, array(
                        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_POST => 1, CURLOPT_HEADER => 0, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $queryUrl, CURLOPT_POSTFIELDS => $queryData));
                $result = curl_exec($curl);
                curl_close($curl);
                $result = json_decode($result, true);
                if (!empty($result['result'])) {
                    $resource->setTVValue(46, $result['result']); //для дальнейшего обновления оплаты
                }
            }
        }
        break;
}
