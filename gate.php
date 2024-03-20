<?php header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");

class Drew
{
  public $data;
  public $campaign_webhook = 'https://api.customer.io/v1/webhook/80924986557a4646';
  public $graphQLUrl = 'https://drewcareid.myshopify.com/admin/api/2024-01/graphql.json';
  public $headers = array(
    'X-Shopify-Access-Token: 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',
    'Content-Type: application/json'
  );

  public function __construct()
  {
    $lines = file(dirname(__FILE__) . '/.env');
    foreach ($lines as $line) {
      list($name, $value) = explode('=', $line, 2);
      putenv(sprintf('%s=%s', trim($name), trim($value)));
    }

    $this->data = new mysqli(getenv('DB_SERVER'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));
  }

  public function clean($post)
  {
    return array_map(function ($p) {
      return (is_array($p)) ? array_map(function ($a) {
        return (is_array($a)) ? array_map(array($this->data, 'real_escape_string'), $a) : $this->data->real_escape_string($a);
      }, $p) : $this->data->real_escape_string($p);
    }, $post);
  }

  public function getCountries()
  {
    $data = $this->data->query("SELECT * FROM `tb_countries` ORDER BY `name`");

    while ($row = mysqli_fetch_assoc($data)) {
      $row['name'] = ucwords(strtolower($row['name']));
      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function getProvinces($post)
  {
    $post = $this->clean($post);
    $resp = array();

    $getCountryId = mysqli_fetch_assoc($this->data->query("SELECT `id` FROM `tb_countries` WHERE `name` = '{$post['id']}' LIMIT 1"));

    $data = $this->data->query("SELECT * FROM `tb_provinces` WHERE `country_id` = '{$getCountryId['id']}' ORDER BY `name`");

    while ($row = mysqli_fetch_assoc($data)) {
      $row['name'] = ucwords(strtolower($row['name']));
      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function getCities($post)
  {
    $post = $this->clean($post);
    $resp = array();

    $getProvinceId = mysqli_fetch_assoc($this->data->query("SELECT `id` FROM `tb_provinces` WHERE `name` = '{$post['id']}' LIMIT 1"));

    $data = $this->data->query("SELECT * FROM `tb_cities` WHERE `province_id` = '{$getProvinceId['id']}' ORDER BY `name`");

    while ($row = mysqli_fetch_assoc($data)) {
      $row['name'] = ucwords(strtolower($row['name']));
      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function getProducts($post)
  {
    $post = $this->clean($post);
    $resp = array();

    $data = $this->data->query("SELECT * FROM tb_added_products WHERE customer_id = '{$post['id_customer']}' ORDER BY `idSerial` ASC");

    while ($row = mysqli_fetch_assoc($data)) {
      if($row['idSerial'] !== null){
        $getSerial = mysqli_fetch_assoc($this->data->query("SELECT `serial_number`, `warranty_period` FROM `tb_serial_numbers` WHERE `idSerial` = '{$row['idSerial']}' ORDER BY `reminder_period` DESC LIMIT 1"));

        $row['serial_number'] = $getSerial['serial_number'];
        $row['warranty_period'] = date('d F Y', strtotime($row['date_purchase'] . ' + ' . $getSerial['warranty_period'] . ' months'));

        $getReminder = mysqli_fetch_assoc($this->data->query("SELECT `date` FROM `tb_timelines` WHERE `idAdded` = '{$row['idAdded']}' AND `type` = 'reminder' AND `reminder_status` = 'active' ORDER BY `date` DESC LIMIT 1"));

        $row['remider_period'] = date('d F Y', strtotime($getReminder['date']));
      }

      $gidProduct = 'gid://shopify/Product/' . $row['product_id'];
      $curl = curl_init();

      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->graphQLUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidProduct.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n\\t\\tmetafield(namespace: \\"custom\\" key: \\"part_replacement\\") {\\n\\t\\t\\tvalue\\n\\t\\t}\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
        CURLOPT_HTTPHEADER => $this->headers
      ));

      $gidProductCurl = curl_exec($curl);
      curl_close($curl);
      $respGP = json_decode($gidProductCurl, true);
      $gidPart = $respGP['data']['product']['metafield']['value'];

      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->graphQLUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidPart.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
        CURLOPT_HTTPHEADER => $this->headers
      ));

      $gidPartCurl = curl_exec($curl);
      curl_close($curl);
      $respPart = json_decode($gidPartCurl, true);
      $row['partUrl'] = $respPart['data']['product']['onlineStoreUrl'];

      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function getTimelines($post)
  {
    $post = $this->clean($post);
    $resp = array();

    $data = $this->data->query("SELECT * FROM tb_timelines WHERE `idAdded` = '{$post['idAdded']}' ORDER BY `type` ASC, `date` DESC");

    while ($row = mysqli_fetch_assoc($data)) {
      if ($row['type'] === 'reminder') {
        $getIdSerial = mysqli_fetch_assoc($this->data->query("SELECT `idSerial` FROM `tb_added_products` WHERE `idAdded` = '{$post['idAdded']}' LIMIT 1"));

        $getReminderPeriod = mysqli_fetch_assoc($this->data->query("SELECT `reminder_period` FROM `tb_serial_numbers` WHERE `idSerial` = '{$getIdSerial['idSerial']}' LIMIT 1"));

        $row['reminder_period'] = $getReminderPeriod['reminder_period'];
      }
      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function partReplaced($post)
  {
    $post = $this->clean($post);

    $this->data->query("UPDATE `tb_timelines` SET `date_replace` = '{$post['replaced']}', `reminder_status` = 'not' WHERE `idTimeline` = '{$post['idTimeline']}'");

    $serialDates = mysqli_fetch_assoc($this->data->query("SELECT `reminder_period` FROM `tb_serial_numbers` WHERE `serial_number` = '{$post['serial_number']}' LIMIT 1"));

    $reminder_period = date('Y-m-d', strtotime($post['dateline'] . ' + ' . $serialDates['reminder_period'] . ' weeks'));

    $this->data->query("INSERT INTO tb_timelines(`idAdded`, `type`, `desc`, `date`, `reminder_status`, `created`) VALUES ('{$post['idAdded']}', 'reminder', 'Part Replacement Reminder', '$reminder_period', 'active', NOW())");

    // If there is no $post['firstName'].
    if (empty($post['firstName'])) {
      $getAdded = mysqli_fetch_assoc($this->data->query("SELECT `first_name`, `email` FROM `tb_added_products` WHERE `idAdded` = '{$post['idAdded']}' LIMIT 1"));
      $post['email'] = $getAdded['email'];
      $post['firstName'] = $getAdded['first_name'];
    }

    // Generate new token for email verification.
    $email_input_token = hash('crc32', strtotime($reminder_period) . $post['serial_number']);

    $this->data->query("UPDATE `tb_added_products` SET `email_input_status` = 1, `email_input_token` = '$email_input_token' WHERE `idAdded` = '{$post['idAdded']}'");

    $gidProduct = 'gid://shopify/Product/' . $post['idProduct'];

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->graphQLUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidProduct.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n\\t\\tmetafield(namespace: \\"custom\\" key: \\"part_replacement\\") {\\n\\t\\t\\tvalue\\n\\t\\t}\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
      CURLOPT_HTTPHEADER => $this->headers
    ));

    $gidProductCurl = curl_exec($curl);
    curl_close($curl);
    $respGP = json_decode($gidProductCurl, true);
    $gidPart = $respGP['data']['product']['metafield']['value'];

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->graphQLUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidPart.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
      CURLOPT_HTTPHEADER => $this->headers
    ));

    $gidPartCurl = curl_exec($curl);
    curl_close($curl);
    $respPart = json_decode($gidPartCurl, true);

    $input_date_link = "https://drewcare.id/pages/enter-part-replacement-date?token=".$email_input_token."&email=".$post['email']."&idAdded=".$post['idAdded']."&serialNumber=".$post['serial_number']."&idProduct=".$post['idProduct']."&idCustomer=".$post['idCustomer'];

    $product_details = array(
      "email" => $post['email'],
      "name" => $post['firstName'],
      "customer_id" => $post['idCustomer'],
      "product" => array(
        "id" => $post['idProduct'],
        "image" => $respGP['data']['product']['featuredImage']['url'],
        "title" => $respGP['data']['product']['title'],
        "date" => $reminder_period,
        "reminder_date" => strtotime($reminder_period),
        "input_date_link" => urldecode($input_date_link),
        "replacement" => array(
          "title" => $respPart['data']['product']['title'],
          "image" => $respPart['data']['product']['featuredImage']['url'],
          "url" => $respPart['data']['product']['onlineStoreUrl']
        ),
      )
    );

    $json_product_details = json_encode($product_details);

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->campaign_webhook,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $json_product_details,
      CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));

    $campaignWebhook = curl_exec($curl);
    curl_close($curl);

    if ($this->data->affected_rows > 0) {
      $resp = array(
        'status' => 'success',
        'title' => 'Part replaced successfully',
        'message' => 'Your part has been replaced.'
      );
    } else {
      $resp = array(
        "status" => "failed",
        "title" => "Part replacement failed",
        "message" => "Sorry, there is error while replacing your part, please try again."
      );
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function checkSerialNumber($post)
  {
    $post = $this->clean($post);
    $resp = array();

    $data = $this->data->query("SELECT idSerial FROM tb_serial_numbers WHERE `serial_number` = '{$post['serial_number']}' AND `status` = 'unused'");

    if ($data->num_rows > 0) {
      $row = mysqli_fetch_assoc($data);
      $resp = array(
        'status' => 'unused',
        'message' => 'Serial number is valid',
        'idSerial' => $row['idSerial']
      );
    } else {
      $resp = array(
        'status' => 'used',
        'message' => 'Sorry, the serial code is missing or has been used. Try again!'
      );
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function checkToken($post)
  {
    $post = $this->clean($post);

    $added = $this->data->query("SELECT `idAdded` FROM tb_added_products WHERE `email_input_token` = '{$post['token']}' AND `idAdded` = '{$post['idAdded']}' AND `email_input_status` = 1 LIMIT 1");

    if ($added->num_rows > 0) {
      $timeline = $this->data->query("SELECT * FROM tb_timelines WHERE `idAdded` = '{$post['idAdded']}' AND `type` = 'reminder' AND `reminder_status` = 'active' LIMIT 1");

      if ($timeline->num_rows > 0) {
        $row = mysqli_fetch_assoc($timeline);
        $resp = array(
          'status' => 'success',
          'message' => 'Token is valid',
          'idTimeline' => $row['idTimeline'],
          'dateline' => $row['date'],
          
        );
      } else {
        $resp = array(
          'status' => 'failed',
          'message' => 'Sorry, the token is invalid. Try again!'
        );
      }
    } else {
      $resp = array(
        'status' => 'failed',
        'message' => 'Sorry, the token is invalid. Try again!'
      );
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function registerWarranty($post)
  {
    $post = $this->clean($post);
    $resp = array();
  
    $serial = mysqli_fetch_assoc($this->data->query("SELECT `idSerial`, `warranty_period`, `reminder_period` FROM tb_serial_numbers WHERE `serial_number` = '{$post['serial_number']}' AND `status` = 'unused' LIMIT 1"));

    $email_input_token = hash('crc32', $post['idAdded'] . $post['email'] . $post['serial_number']);

    $this->data->query("UPDATE `tb_added_products` SET `idSerial` = '{$serial['idSerial']}', `email_input_status` = 1, `email_input_token` = '$email_input_token' WHERE `idAdded` = '{$post['idAdded']}'");

    $this->data->query("UPDATE `tb_serial_numbers` SET `status` = 'used' WHERE `serial_number` = '{$post['serial_number']}'");

    $date_register = date('Y-m-d', strtotime('today'));
    $limited_warranty = date('Y-m-d', strtotime($date_register . ' + ' . $serial['warranty_period'] . ' months'));

    $this->data->query("INSERT INTO tb_timelines(`idAdded`, `type`, `desc`, `date`, `created`) VALUES ('{$post['idAdded']}', 'info', 'Warranty activated', '$limited_warranty', NOW())");

    $reminder_period = date('Y-m-d', strtotime($date_register . ' + ' . $serial['reminder_period'] . ' weeks'));

    $this->data->query("INSERT INTO tb_timelines(`idAdded`, `type`, `desc`, `date`, `reminder_status`, `created`) VALUES ('{$post['idAdded']}', 'reminder', 'Part Replacement Reminder', '$reminder_period', 'active', NOW())");

    $this->cuPersonCustomerIo($post['idCustomer'], $post['email']);

    $gidProduct = 'gid://shopify/Product/' . $post['idProduct'];

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->graphQLUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidProduct.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n\\t\\tmetafield(namespace: \\"custom\\" key: \\"part_replacement\\") {\\n\\t\\t\\tvalue\\n\\t\\t}\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
      CURLOPT_HTTPHEADER => $this->headers
    ));

    $gidProductCurl = curl_exec($curl);
    curl_close($curl);
    $respGP = json_decode($gidProductCurl, true);
    $gidPart = $respGP['data']['product']['metafield']['value'];

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->graphQLUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidPart.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
      CURLOPT_HTTPHEADER => $this->headers
    ));

    $gidPartCurl = curl_exec($curl);
    curl_close($curl);
    $respPart = json_decode($gidPartCurl, true);

    $input_date_link = "https://drewcare.id/pages/enter-part-replacement-date?token=".$email_input_token."&email=".$post['email']."&idAdded=".$post['idAdded']."&serialNumber=".$post['serial_number']."&idProduct=".$post['idProduct']."&idCustomer=".$post['idCustomer'];

    $product_details = array(
      "email" => $post['email'],
      "name" => $post['firstName'],
      "customer_id" => $post['idCustomer'],
      "product" => array(
        "id" => $post['idProduct'],
        "image" => $respGP['data']['product']['featuredImage']['url'],
        "title" => $respGP['data']['product']['title'],
        "date" => $date_register,
        "reminder_date" => strtotime($reminder_period),
        "input_date_link" => urldecode($input_date_link),
        "replacement" => array(
          "title" => $respPart['data']['product']['title'],
          "image" => $respPart['data']['product']['featuredImage']['url'],
          "url" => $respPart['data']['product']['onlineStoreUrl']
        ),
      )
    );

    $json_product_details = json_encode($product_details);

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->campaign_webhook,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $json_product_details,
      CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    ));

    $campaignWebhook = curl_exec($curl);
    curl_close($curl);

    $resp = array(
      'status' => 'success',
      'title' => 'Warranty registered successfully',
      'message' => 'Your product guarantee has been registered.'
    );

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function cuPersonCustomerIo($identifier, $email)
  {
    $site_id = 'e16f20bacb7f6388a1d0';
    $api_key = 'a0e0b045e1194ed5196b';

    $data = array(
      'id' => $identifier,
      'email' => $email
    );

    $json_data = json_encode($data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://track.customer.io/api/v1/customers/{$identifier}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Authorization: Basic ' . base64_encode("$site_id:$api_key"),
      'Content-Type: application/json'
    ));

    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    $response = curl_exec($ch);
    curl_close($ch);
  }

  public function addProduct($post)
  {
    $post = $this->clean($post);
    $idAdded = 'add-' . $post['model_unit'] . '-' . date('jnygis');
    $date_purchase = date('Y-m-d', strtotime($post['purchase_date']));
    $email_input_token = hash('crc32', $idAdded . $post['email'] . $date_purchase);
    $resp = array();

    $this->data->query("INSERT INTO tb_added_products(`idAdded`, `idSerial`, `customer_id`, `product_id`, `first_name`, `email`, `agree_marketing`, `purchase_location`, `date_purchase`, `country`, `province`, `city`, `warranty_status`, `email_input_status`, `email_input_token`, `created`) VALUES ('$idAdded', '{$post['idSerial']}', '{$post['id_customer']}', '{$post['model_unit']}', '{$post['first_name']}', '{$post['email']}', {$post['agree_marketing']}, '{$post['purchase_location']}', '$date_purchase', '{$post['country']}', '{$post['province']}', '{$post['city']}', 'active', 1, '$email_input_token', NOW())");

    if ($this->data->affected_rows > 0){
      $this->data->query("UPDATE `tb_serial_numbers` SET `status` = 'used' WHERE `serial_number` = '{$post['serial_number']}'");

      $serialDates = mysqli_fetch_assoc($this->data->query("SELECT `warranty_period`, `reminder_period` FROM `tb_serial_numbers` WHERE `idSerial` = '{$post['idSerial']}' LIMIT 1"));

      $limited_warranty = date('Y-m-d', strtotime($date_purchase . ' + ' . $serialDates['warranty_period'] . ' months'));

      $this->data->query("INSERT INTO tb_timelines(`idAdded`, `type`, `desc`, `date`, `created`) VALUES ('$idAdded', 'info', 'Warranty activated', '$limited_warranty', NOW())");

      $reminder_period = date('Y-m-d', strtotime($date_purchase . ' + ' . $serialDates['reminder_period'] . ' weeks'));

      $this->data->query("INSERT INTO tb_timelines(`idAdded`, `type`, `desc`, `date`, `reminder_status`, `created`) VALUES ('$idAdded', 'reminder', 'Part Replacement Reminder', '$reminder_period', 'active', NOW())");

      $this->cuPersonCustomerIo($post['id_customer'], $post['email']);
      $gidProduct = 'gid://shopify/Product/' . $post['model_unit'];

      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->graphQLUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidProduct.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n\\t\\tmetafield(namespace: \\"custom\\" key: \\"part_replacement\\") {\\n\\t\\t\\tvalue\\n\\t\\t}\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
        CURLOPT_HTTPHEADER => $this->headers
      ));

      $gidProductCurl = curl_exec($curl);
      curl_close($curl);
      $respGP = json_decode($gidProductCurl, true);
      $gidPart = $respGP['data']['product']['metafield']['value'];

      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->graphQLUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$gidPart.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n    onlineStoreUrl\\n  }\\n}","variables":{}}',
        CURLOPT_HTTPHEADER => $this->headers
      ));

      $gidPartCurl = curl_exec($curl);
      curl_close($curl);
      $respPart = json_decode($gidPartCurl, true);

      $input_date_link = "https://drewcare.id/pages/enter-part-replacement-date?token=".$email_input_token."&email=".$post['email']."&idAdded=".$idAdded."&serialNumber=".$post['serial_number']."&idProduct=".$post['model_unit']."&idCustomer=".$post['id_customer'];

      $product_details = array(
        "email" => $post['email'],
        "name" => $post['first_name'],
        "customer_id" => $post['id_customer'],
        "product" => array(
          "id" => $post['model_unit'],
          "image" => $respGP['data']['product']['featuredImage']['url'],
          "title" => $respGP['data']['product']['title'],
          "date" => $date_purchase,
          "reminder_date" => strtotime($reminder_period),
          "input_date_link" => urldecode($input_date_link),
          "replacement" => array(
            "title" => $respPart['data']['product']['title'],
            "image" => $respPart['data']['product']['featuredImage']['url'],
            "url" => $respPart['data']['product']['onlineStoreUrl']
          ),
        )
      );

      $json_product_details = json_encode($product_details);

      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => $this->campaign_webhook,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $json_product_details,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
      ));

      $campaignWebhook = curl_exec($curl);
      curl_close($curl);

      $resp = array(
        'status' => 'success',
        'title' => 'Product added successfully',
        'message' => 'Your product guarantee has been registered.'
      );
    } else {
      $resp = array(
        "status" => "failed",
        "title" => "Product guarantee registration failed",
        "message" => "Sorry, there is error while adding your product, please try again."
      );
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function orderFulfilled($post)
  {
    $order_id = $post['admin_graphql_api_id'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->graphQLUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{"query":"query{\\n  order(id: \\"'.$order_id.'\\"){\\n    customer {\\n        id\\n        displayName\\n    }\\n    email\\n    lineItems(first: 250) {\\n        nodes{\\n            quantity\\n            product {\\n                id\\n                title\\n                featuredImage {\\n                    url\\n                }\\n\\n                metafields(first:20 namespace:\\"custom\\" ) {\\n                    edges {\\n                        node {\\n                            key\\n                            value\\n                        }\\n                    }\\n                }\\n            }\\n        }\\n    }\\n    billingAddress {\\n        country\\n        city\\n        province\\n    }\\n  }\\n}","variables":{}}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $resp = json_decode($response, true);
    $nodes = $resp['data']['order']['lineItems']['nodes'];
    $date_purchase = date('Y-m-d', strtotime('today'));
    $customer_id = str_replace('gid://shopify/Customer/', '', $resp['data']['order']['customer']['id']);

    foreach ($nodes as $item){
      if ($item['product']['metafields']['edges'][1]['node']['value'] === "true") {
        for ($i = 1; $i <= $item['quantity']; $i++) {
          $product_id = str_replace('gid://shopify/Product/', '', $item['product']['id']);
          $idAdded = 'add-'. date('jnygis').'-'. $product_id .'-'. $i;

          $this->data->query("INSERT INTO tb_added_products(`idAdded`, `customer_id`, `product_id`, `first_name`, `email`, `agree_marketing`, `purchase_location`, `date_purchase`, `country`, `province`, `city`, `warranty_status`, `created`) VALUES ('$idAdded', '$customer_id', '$product_id', '{$resp['data']['order']['displayName']}', '{$resp['data']['order']['email']}', 1, 'Drew Website', '$date_purchase', '{$resp['data']['order']['billingAddress']['country']}', '{$resp['data']['order']['billingAddress']['province']}', '{$resp['data']['order']['billingAddress']['city']}', 'not', NOW())");
        }
      }
    }

    // Create/update person to Customer.io
    $this->cuPersonCustomerIo($customer_id, $resp['data']['order']['email']);
  }

  public function saveSurvey($post)
  {
    $decodedAnswers = json_decode($post['survey'], true);
    $formattedAnswers = [];

    foreach ($decodedAnswers as $key => $value) {
      $formattedAnswers[] = "\"$key: $value\"";
    }

    foreach ($formattedAnswers as &$answer) {
      $answer = '\\"' . trim($answer, '"') . '\\"';
    }
  
    $formattedAnswers = '[' . implode(', ', $formattedAnswers) . ']';
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://drewcareid.myshopify.com/admin/api/2024-01/customers/'.$post['id_customer'].'/metafields.json',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS =>'{
        "metafield": {
          "namespace": "custom",
          "key": "survey_answers",
          "value": "'.$formattedAnswers.'",
          "type": "list.single_line_text_field"
        }
    }',
      CURLOPT_HTTPHEADER => $this->headers
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    if($response){
      $resp = array(
        'status' => 'success',
        'message' => ''
      );
    } else {
      $resp = array(
        "status" => 'failed',
        "message" => ''
      );
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function getRawResponse($post){
    $json_post = json_encode($post, true);

    $this->data->query("INSERT INTO tb_raw_responses(`content`) VALUES ('$json_post')");
  }
}

if (!empty($_POST)){
  $data = $_POST;
} else {
  $json_data = file_get_contents('php://input');
  $data = json_decode($json_data, true);
}

$action = new Drew;
$method = filter_input(INPUT_GET, 'method', FILTER_SANITIZE_SPECIAL_CHARS);

if ($method !== 'orderFulfilled'){
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== 'https://drewcare.id') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
  }
}

call_user_func_array(array($action, $method), array($data));