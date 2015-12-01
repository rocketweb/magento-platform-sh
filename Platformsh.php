<?php

class Platformsh
{
    protected $platformReadWriteDirs = ['var', 'app/etc', 'media'];
    
    protected $urlUnsecure = '';
    protected $urlSecure = '';

    protected $defaultCurrency = 'USD';

    protected $dbHost;
    protected $dbName;
    protected $dbUser;

    protected $adminUsername;
    protected $adminFirstname;
    protected $adminLastname;
    protected $adminEmail;
    protected $adminPassword;

    protected $redisCacheHost;
    protected $redisCacheScheme;
    protected $redisCachePort;

    protected $redisFpcHost;
    protected $redisFpcScheme;
    protected $redisFpcPort;

    protected $redisSessionHost;
    protected $redisSessionScheme;
    protected $redisSessionPort;

    protected $lastOutput = array();
    protected $lastStatus = null;

    /**
     * Prepare data needed to install Magento
     */
    public function init()
    {
        echo "Preparing environment specific data." . PHP_EOL;

        $routes = $this->getRoutes();
        $relationships = $this->getRelationships();
        $var = $this->getVariables();

        foreach($routes as $key => $val) {
            if(strpos($key,"http://")===0 && $val["type"]==="upstream"){
                $this->urlUnsecure = $key;
                break;
            }
        }

        foreach($routes as $key => $val){
            if(strpos($key,"https://")===0 && $val["type"]==="upstream") {
                $this->urlSecure = $key;
                break;
            }
        }

        $this->dbHost = $relationships["database"][0]["host"];
        $this->dbName = $relationships["database"][0]["path"];
        $this->dbUser = $relationships["database"][0]["username"];

        $this->adminUsername = isset($var["ADMIN_USERNAME"]) ? $var["ADMIN_USERNAME"] : "admin";
        $this->adminFirstname = isset($var["ADMIN_FIRSTNAME"]) ? $var["ADMIN_FIRSTNAME"] : "John";
        $this->adminLastname = isset($var["ADMIN_LASTNAME"]) ? $var["ADMIN_LASTNAME"] : "Doe";
        $this->adminEmail = isset($var["ADMIN_EMAIL"]) ? $var["ADMIN_EMAIL"] : "john@example.com";
        $this->adminPassword = isset($var["ADMIN_PASSWORD"]) ? $var["ADMIN_PASSWORD"] : "admin12";

        $this->redisCacheHost = $relationships['rediscache'][0]['host'];
        $this->redisCacheScheme = $relationships['rediscache'][0]['scheme'];
        $this->redisCachePort = $relationships['rediscache'][0]['port'];

        $this->redisFpcHost = $relationships['redisfpc'][0]['host'];
        $this->redisFpcScheme = $relationships['redisfpc'][0]['scheme'];
        $this->redisFpcPort = $relationships['redisfpc'][0]['port'];

        $this->redisSessionHost = $relationships['redissession'][0]['host'];
        $this->redisSessionScheme = $relationships['redissession'][0]['scheme'];
        $this->redisSessionPort = $relationships['redissession'][0]['port'];
    }

    public function build()
    {
        $this->clearTemp();

        // Move directories away
        foreach ($this->platformReadWriteDirs as $dir) {
            exec(sprintf('mkdir -p ../init/%s', $dir));
            exec(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/* ../init/%s/"', $dir, $dir));
            exec(sprintf('rm -rf %s', $dir));
            exec(sprintf('mkdir %s', $dir));
        }
    }

    public function deploy()
    {
        // Copy read-write directories back
        foreach ($this->platformReadWriteDirs as $dir) {
            exec(sprintf('/bin/bash -c "shopt -s dotglob; cp -R ../init/%s/* %s/ || true"', $dir, $dir));
            echo sprintf('Copied directory: %s', $dir) . PHP_EOL;
        }

        // Remove directory (can't do this as file system is not readable at this point)
        //$this->clearTemp();

        if (!file_exists('app/etc/local.xml')) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
    }

    /**
     * @return mixed
     */
    protected function getRoutes()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);
    }

    /**
     * @return mixed
     */
    protected function getRelationships()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true);
    }

    /**
     * @return mixed
     */
    protected function getVariables()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);
    }

    /**
     * Run Magento installation
     */
    protected function installMagento()
    {
        echo "File local.xml does not exist. Installing Magento." . PHP_EOL;

        exec(
            "php -f install.php -- \
            --default_currency $this->defaultCurrency \
            --url $this->urlUnsecure \
            --secure_base_url $this->urlSecure \
            --skip_url_validation 'yes' \
            --license_agreement_accepted 'yes' \
            --locale 'en_US' \
            --timezone 'America/Los_Angeles' \
            --db_host $this->dbHost \
            --db_name $this->dbName \
            --db_user $this->dbUser \
            --db_pass '' \
            --use_rewrites 'yes' \
            --use_secure 'yes' \
            --use_secure_admin 'yes' \
            --admin_username $this->adminUsername \
            --admin_firstname $this->adminFirstname \
            --admin_lastname $this->adminLastname \
            --admin_email $this->adminEmail \
            --admin_password $this->adminPassword",
            $this->lastOutput, 
            $this->lastStatus
        );
    }

    /**
     * Update Magento configuration
     */
    protected function updateMagento()
    {
        echo "File local.xml exists." . PHP_EOL;

        $this->updateConfiguration();

        $this->updateDatabaseConfiguration();

        $this->clearCache();
    }

    protected function updateDatabaseConfiguration()
    {
        echo "Updating database configuration." . PHP_EOL;

        // Update site URLs
        exec("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->urlUnsecure' where path = 'web/unsecure/base_url' and scope_id = '0';\" $this->dbName");
        exec("mysql -u user -h $this->dbHost -e \"update core_config_data set value = '$this->urlSecure' where path = 'web/secure/base_url' and scope_id = '0';\" $this->dbName");

        // Update admin credentials
        exec("mysql -u user -h $this->dbHost -e \"update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername', password = md5('$this->adminPassword') where user_id = '1';\" $this->dbName");
    }

    /**
     * Clear content of temp directory
     */
    protected function clearTemp()
    {
        exec('rm -rf ../init/*');
    }

    /**
     * Clear Magento file based cache
     */
    protected function clearCache()
    {
        echo "Clearing cache." . PHP_EOL;
        exec('rm -rf var/cache/* var/full_page_cache/* media/css/* media/js/*');
    }

    /**
     * Update local.xml file content
     */
    protected function updateConfiguration()
    {
        echo "Updating local.xml configuration." . PHP_EOL;

        $configFileName = "app/etc/local.xml";

        $config = simplexml_load_file($configFileName);

        $dbConfig = $config->xpath('/config/global/resources/default_setup/connection')[0];
        $cacheBackend = $config->xpath('/config/global/cache/backend')[0];

        $dbConfig->username = $this->dbUser;
        $dbConfig->host = $this->dbHost;
        $dbConfig->dbname = $this->dbName;

        if ('Cm_Cache_Backend_Redis' == $cacheBackend) {
            $cacheConfig = $config->xpath('/config/global/cache/backend_options')[0];
            $fpcConfig = $config->xpath('/config/global/full_page_cache/backend_options')[0];
            $sessionConfig = $config->xpath('/config/global/redis_session')[0];

            $cacheConfig->port = $this->redisCachePort;
            $cacheConfig->server = $this->redisCacheHost;

            $fpcConfig->port = $this->redisFpcPort;
            $fpcConfig->server = $this->redisFpcHost;

            $sessionConfig->port = $this->redisSessionPort;
            $sessionConfig->host = $this->redisSessionHost;
        }

        $config->saveXML($configFileName);
    }
}