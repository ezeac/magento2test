sudo chmod -R 777 . &&
sudo php bin/magento maintenance:enable &&
sudo rm -rf var/cache var/generation var/di var/view_preprocessed var/report var/page_cache pub/static/_cache pub/static/_requirejs pub/static/adminhtml pub/static/deployed_version.txt pub/static/frontend;

sudo php bin/magento setup:upgrade &&
sudo php bin/magento setup:di:compile &&
sudo chmod -R 777 .;

sudo php bin/magento setup:static-content:deploy es_ES en_US -f &&
sudo chmod -R 777 . &&
sudo php bin/magento cache:flush &&
sudo php bin/magento cache:enable &&
sudo php bin/magento maintenance:disable &&
sudo chown -R $USER:www-data . && sudo chmod -R 664 . && sudo find . -type d -exec chmod 775 {} \; && sudo chmod u+x bin/magento && sudo chmod -R 777 pub var generated && sudo chmod u+x bin/magento deploy-sample.sh
