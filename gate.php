<?php header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");

class Drew
{
  public $data;

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

    $data = $this->data->query("SELECT tb_added_products.*, tb_serial_numbers.serial_number FROM tb_added_products, tb_serial_numbers WHERE tb_added_products.customer_id = '{$post['id_customer']}' AND tb_added_products.idSerial = tb_serial_numbers.idSerial");

    while ($row = mysqli_fetch_assoc($data)) {
      $resp[] = $row;
    }

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($resp);
    exit(0);
  }

  public function check_serial_number($post)
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

  public function addProduct($post)
  {
    $post = $this->clean($post);
    $idAdded = 'add-' . $post['model_unit'] . '-' . date('jnygis');
    $date_purchase = date('Y-m-d', strtotime($post['purchase_date']));
    $resp = array();

    $this->data->query("INSERT INTO tb_added_products(`idAdded`, `idSerial`, `customer_id`, `product_id`, `email`, `agree_marketing`, `purchase_location`, `date_purchase`, `country`, `province`, `city`, `created`) VALUES ('$idAdded', '{$post['idSerial']}', '{$post['id_customer']}', '{$post['model_unit']}', '{$post['email']}', {$post['agree_marketing']}, '{$post['purchase_location']}', '$date_purchase', '{$post['country']}', '{$post['province']}', '{$post['city']}', NOW())");

    if ($this->data->affected_rows > 0) {
      $this->data->query("UPDATE `tb_serial_numbers` SET `status` = 'used' WHERE `serial_number` = '{$post['serial_number']}'");
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

  public function rawResponse($post)
  {
    $this->data->query("INSERT INTO tb_raw_responses(`content`) VALUES('{$post}')");
  }

  public function orderFulfilled($post)
  {

    // GET ORDER DETAILS VIA SHOPIFY GRAPHQL
    $graphQLUrl = 'https://binsar-playground.myshopify.com/admin/api/2024-01/graphql.json';
    $headers = array(
      'X-Shopify-Access-Token: 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',
      'Content-Type: application/json'
    );
    // $order_id = $post['admin_graphql_api_id'];
    $order_id = 'gid://shopify/Order/5794931245301';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphQLUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{"query":"query{\\n  order(id: \\"'.$order_id.'\\"){\\n    customer {\\n        id\\n        displayName\\n    }\\n    email\\n    lineItems(first: 250) {\\n        nodes{\\n            quantity\\n            product {\\n                id\\n                title\\n                featuredImage {\\n                    url\\n                }\\n\\n                metafields(first:20 namespace:\\"custom\\" ) {\\n                    edges {\\n                        node {\\n                            key\\n                            value\\n                        }\\n                    }\\n                }\\n            }\\n        }\\n    }\\n    billingAddress {\\n        country\\n        city\\n        province\\n    }\\n  }\\n}","variables":{}}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $product_details = array();
    $resp = json_decode($response, true);
    $nodes = $resp['data']['order']['lineItems']['nodes'];
    $date_purchase = date('Y-m-d', strtotime('today'));
    $customer_id = str_replace('gid://shopify/Customer/', '', $resp['data']['order']['customer']['id']);

    foreach ($nodes as $item) {
      if ($item['product']['metafields']['edges'][0]['node']['value'] === "true") {
        for ($i = 0; $i < $item['quantity']; $i++) {
          $replacementId = $item['product']['metafields']['edges'][2]['node']['value'];
          $product_id = str_replace('gid://shopify/Product/', '', $item['product']['id']);
          $idAdded = 'add-' . $product_id . '-' . date('jnygis');

          $this->data->query("INSERT INTO tb_added_products(`idAdded`, `customer_id`, `product_id`, `email`, `agree_marketing`, `purchase_location`, `date_purchase`, `country`, `province`, `city`, `created`) VALUES ('$idAdded', '$customer_id', '$product_id', '{$resp['data']['order']['email']}', 1, 'Drew Website', '$date_purchase', '{$resp['data']['order']['billingAddress']['country']}', '{$resp['data']['order']['billingAddress']['province']}', '{$resp['data']['order']['billingAddress']['city']}', NOW())");

          $curl = curl_init();

          curl_setopt_array($curl, array(
            CURLOPT_URL => $graphQLUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{"query":"query {\\n  product(id: \\"'.$replacementId.'\\"){\\n    id\\n    title\\n    featuredImage {\\n      url\\n    }\\n    onlineStorePreviewUrl\\n  }\\n}","variables":{}}',
            CURLOPT_HTTPHEADER => $headers,
          ));

          $responseReplacement = curl_exec($curl);
          curl_close($curl);

          $respRepl = json_decode($responseReplacement, true);

          $product_details[] = array(
            "id" => $product_id,
            "product_title" => $item['product']['title'],
            "image" => $item['product']['featuredImage']['url'],
            "replacement" => array(
              "title" => $respRepl['data']['product']['title'],
              "image" => $respRepl['data']['product']['featuredImage']['url'],
              "url" => $respRepl['data']['product']['onlineStorePreviewUrl']
            ),
            "city" => $resp['data']['order']['billingAddress']['city'],
            "purchase_date" => $date_purchase,
            "purchase_location" => "Website"
          );
        }
      }
    }

    // CREATE/UPDATE PEOPLE IN CUSTOMER.IO
    $site_id = 'e16f20bacb7f6388a1d0';
    $api_key = 'a0e0b045e1194ed5196b';

    $identifier = $customer_id;
    $email = $resp['data']['order']['email'];
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

    // INSERT ATTRIBUTES
    $curl = curl_init();

    $attr = array(
      "userId" => $customer_id,
      "traits" => array(
        "email" => $resp['data']['order']['email'],
        "name" => $resp['data']['order']['customer']['displayName'],
        "products" => $product_details
      )
    );

    $attr_json = json_encode($attr);

    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://cdp.customer.io/v1/identify',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $attr_json,
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Authorization: Basic ODE4MGMwMzM3ZTlhNzY0Yzg2ODg6'
      ),
    ));

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
      echo 'Curl error: ' . curl_error($curl);
    }

    echo $attr_json;

    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    echo 'HTTP response code: ' . $http_code;
    curl_close($curl);
  }
}

if (!empty($_POST)) {
  $data = $_POST;
} else {
  $json_data = file_get_contents('php://input');
  $data = json_decode($json_data, true);
}

$action = new Drew;
$method = filter_input(INPUT_GET, 'method', FILTER_SANITIZE_SPECIAL_CHARS);
call_user_func_array(array($action, $method), array($data));
