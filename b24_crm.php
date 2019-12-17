<?php
/*
Обновление данных лида в битриксе по id
 */
function BX_updateLeadbyId($id, $fields)
{
    global $modx;
    $queryUrl = $modx->getOption('BX_CRM_path') . '/crm.lead.update.json';
    $queryData = http_build_query(array(
        'id' => $id,
        'fields' => $fields,
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
    return $result;
}
