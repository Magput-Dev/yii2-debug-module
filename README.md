# Debug модуль

### Перед установкой:
1) убедиться, что в dev-Dockerfile установлено расширение для работы с Mongo. В php 7.2
```bash
   RUN apt-get update && apt-get install -y libssl-dev \
   && pecl install mongodb-1.9.2 \
   && docker-php-ext-enable mongodb
```

В php >= 8.1
```bash
RUN pecl install mongodb \
&& docker-php-ext-enable mongodb
```

2) убедиться, что в mongo созданы collections для сервиса, например, "auth_api_debug_data", "auth_api_query_log"

### Установка пакета 
добавить в composer.json
```json
"repositories": [
   {
   "type": "vcs",
   "url": "https://github.com/Magput-Dev/yii2-debug-module.git"
   }
]
   ```

php >= 8.1
```bash
composer require magput-dev/yii2-debug-module:dev-8-1
```

php 7.2
```bash
composer require magput-dev/yii2-debug-module:dev-7-2
```


### Настройка конфигов
1) указать и прокинуть ENV переменные
```
- MONGO_LOGS_HOST
- MONGO_LOGS_PORT
- MONGO_LOGS_USER
- MONGO_LOGS_PASS
- MONGO_LOGS_DBNAME
- MONGO_LOGS_DBAUTH
```
2) в config/main.php добавить подключение к mongo
```php 
'mongodbForLogs' => [
    'class' => \yii\mongodb\Connection::class,
    'dsn' => 'mongodb://' . $_ENV['MONGO_LOGS_HOST'] . ':' . $_ENV['MONGO_LOGS_PORT'] . '/' . $_ENV['MONGO_LOGS_DBNAME'],
    'options' => [
        'authSource' => $_ENV['MONGO_LOGS_DBAUTH'],
        'username' => $_ENV['MONGO_LOGS_USER'],
        'password' => $_ENV['MONGO_LOGS_PASS'],
    ]
],
```
3) в config/main.php указать конфиг для debug
```php
   use Magput\Debug\data\MongoDataStorage;
   use Magput\Debug\DebugModule;
   use Magput\Debug\LogTarget;
   use Magput\Debug\panels\CustomDbPanel;
   use Magput\Debug\panels\CustomRequestPanel;
   ...
'debug' =>
[
   'historySize' => 10000,
   'allowedIPs' => ['*'],
   'tagPrefix' => $_ENV['DEBUG_TAG_PREFIX'] ?? '',
   'oneCSecretKey' => $_ENV['ONE_C_SECRET_KEY'] ?? '',
   'gatewayPath' => $_ENV['GATEWAY_PATH'] ?? '',
   'checkAccessCallback' => function ($action) {
       if (
       Yii::$app->controller
       && Yii::$app->controller->module->id === 'debug'
       && Yii::$app->controller->id === 'default'
       ) {
           if (Yii::$app->session->get('md') == $_ENV['DEBUG_SECRET_KEY']) {
               return true;
           } elseif (Yii::$app->request->get('md') == $_ENV['DEBUG_SECRET_KEY']) {
               Yii::$app->session->set('md', $_ENV['DEBUG_SECRET_KEY']);
               return true;
           }
       }
       return false;
   },
   'logTarget' => [
       'class' => LogTarget::class,
       'userIdCallback' => function($identity, $request) {
           if ($identity instanceof \api\auth\ApiUser) {
               return (string)$identity->getName();
           }
           if ($identity instanceof \api\auth\Manager) {
               return (string)$identity->getId();
           }
           if ($request->get('secret') === ($_ENV['ONE_C_SECRET_KEY'] ?? null)) {
               return '1C_user';
           }
           return null;
       },
   ],
   'panels' => array_merge(
       [
           'request' => ['class' => CustomRequestPanel::class],
           'db' => ['class' => CustomDbPanel::class],
       ],
       YII_ENV_PROD ? [
           'config' => false,
           'mail' => false,
           'event' => false,
           'dump' => false,
       ] : []
   ),
   'dataStorageConfig' => [
       'class' => MongoDataStorage::class,
       'mongoComponent' => 'mongodbForLogs',
       'dataCollectionName' => 'manage_debug_data',
       'indexCollectionName' => 'manage_query_log',
   ],
],
```
