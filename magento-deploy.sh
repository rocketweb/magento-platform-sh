#!/bin/bash

#
# Prepare data needed to install Magento
#

echo "Preparing environment specific data."

URL=`php -r '$routes=json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);foreach($routes as $key => $val){if(strpos($key,"http://")===0 && $val["type"]==="upstream"){echo $key;break;}}'`
URL_SSL=`php -r '$routes=json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);foreach($routes as $key => $val){if(strpos($key,"https://")===0 && $val["type"]==="upstream"){echo $key;break;}}'`

DB_HOST=`php -r 'echo json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true)["database"][0]["host"];'`
DB_NAME=`php -r 'echo json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true)["database"][0]["path"];'`
DB_USER=`php -r 'echo json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true)["database"][0]["username"];'`
#DB_PASS=''

ADMIN_USERNAME=`php -r '$var=json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);echo isset($var["ADMIN_USERNAME"])?$var["ADMIN_USERNAME"]:"admin";'`
ADMIN_FIRSTNAME=`php -r '$var=json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);echo isset($var["ADMIN_FIRSTNAME"])?$var["ADMIN_FIRSTNAME"]:"John";'`
ADMIN_LASTNAME=`php -r '$var=json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);echo isset($var["ADMIN_LASTNAME"])?$var["ADMIN_LASTNAME"]:"Doe";'`
ADMIN_EMAIL=`php -r '$var=json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);echo isset($var["ADMIN_EMAIL"])?$var["ADMIN_EMAIL"]:"john@example.com";'`
ADMIN_PASSWORD=`php -r '$var=json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);echo isset($var["ADMIN_PASSWORD"])?$var["ADMIN_PASSWORD"]:"admin12";'`

DEFAULT_CURRENCY='USD'

#
# Allow to cp dot files easily
#
shopt -s dotglob

#
# Read read-write directories (Unix)
#
readarray -t  dirs < .platform-read-write-dirs

#
# Read read-write directories (OS X)
#
#while IFS=: read -r dir; do
#    dirs+=($dir)
#done < <(grep "" .platform-read-write-dirs)

#
# Copy directories back
#
for dir in "${dirs[@]}"
do
    cp -R ../init/$dir/* $dir/ || true
    echo "Copied directory: $dir"
done

#
# Remove directory
#
rm -rf ../init/*

if [ ! -f app/etc/local.xml ]; 
then
    echo "File local.xml does not exist. Installing Magento."
    
    #
    # Run Magento installation
    #
    php -f install.php -- \
    --default_currency $DEFAULT_CURRENCY \
    --url $URL \
    --secure_base_url $URL_SSL \
    --skip_url_validation 'yes' \
    --license_agreement_accepted 'yes' \
    --locale 'en_US' \
    --timezone 'America/Los_Angeles' \
    --db_host $DB_HOST \
    --db_name $DB_NAME \
    --db_user $DB_USER \
    --db_pass '' \
    --use_rewrites 'yes' \
    --use_secure 'yes' \
    --use_secure_admin 'yes' \
    --admin_username $ADMIN_USERNAME \
    --admin_firstname $ADMIN_FIRSTNAME \
    --admin_lastname $ADMIN_LASTNAME \
    --admin_email $ADMIN_EMAIL \
    --admin_password $ADMIN_PASSWORD

else
    #
    # Update Magento configuration
    #
    echo "File local.xml exists."

    #
    # Update local.xml
    #
    echo "Updating local.xml configuration."
    sed -i "s/\(<username><\!\[CDATA\[\).*\(\]\]><\/username>\)/\1$DB_USER\2/" 'app/etc/local.xml'
    sed -i "s/\(<host><\!\[CDATA\[\).*\(\]\]><\/host>\)/\1$DB_HOST\2/" 'app/etc/local.xml'
    sed -i "s/\(<dbname><\!\[CDATA\[\).*\(\]\]><\/dbname>\)/\1$DB_NAME\2/" 'app/etc/local.xml'

    #
    # Update database
    #
    echo "Updating database configuration."

    #
    # Update site URLs
    #
    mysql -u user -h $DB_HOST -e "update core_config_data set value = '$URL' where path = 'web/unsecure/base_url' and scope_id = '0';" $DB_NAME
    mysql -u user -h $DB_HOST -e "update core_config_data set value = '$URL_SSL' where path = 'web/secure/base_url' and scope_id = '0';" $DB_NAME

    #
    # Update admin credentials
    #
    mysql -u user -h $DB_HOST -e "update admin_user set firstname = '$ADMIN_FIRSTNAME', lastname = '$ADMIN_LASTNAME', email = '$ADMIN_EMAIL', username = '$ADMIN_USERNAME', password = md5('$ADMIN_PASSWORD') where user_id = '1';" $DB_NAME

    #
    # Clear cache
    #
    echo "Clearing cache."
    rm -rf var/cache/* var/full_page_cache/* media/css/* media/js/*
fi