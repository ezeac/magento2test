# Mage2 Module Kudos ImageSync

    ``kudos/module-imagesync``

 - [Main Functionalities](#markdown-header-main-functionalities)
 - [Installation](#markdown-header-installation)
 - [Configuration](#markdown-header-configuration)
 - [Specifications](#markdown-header-specifications)
 - [Attributes](#markdown-header-attributes)


## Main Functionalities


## Installation
\* = in production please use the `--keep-generated` option

### Type 1: Zip file

 - Unzip the zip file in `app/code/Kudos`
 - Enable the module by running `php bin/magento module:enable Kudos_ImageSync`
 - Apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`

### Type 2: Composer

 - Make the module available in a composer repository for example:
    - private repository `repo.magento.com`
    - public repository `packagist.org`
    - public github repository as vcs
 - Add the composer repository to the configuration by running `composer config repositories.repo.magento.com composer https://repo.magento.com/`
 - Install the module composer by running `composer require kudos/module-imagesync`
 - enable the module by running `php bin/magento module:enable Kudos_ImageSync`
 - apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`


## Configuration

 - Drive Spreadsheet ID (imagesync/spreadsheets/spreadsheet_id)

 - Range of columns (imagesync/spreadsheets/range)

 - Column Number for SKU (imagesync/spreadsheets/sku_column)

 - Column Number for FIRST IMAGE (imagesync/spreadsheets/image1_column)

 - Column Number for SECOND IMAGE (imagesync/spreadsheets/image2_column)

 - Column Number for THIRD IMAGE (imagesync/spreadsheets/image3_column)

 - client_id (imagesync/googleapicredentials/client_id)

 - project_id (imagesync/googleapicredentials/project_id)

 - client_secret (imagesync/googleapicredentials/client_secret)

 - show_info_logs (imagesync/logsandinfo/show_info_logs)

 - show_error_logs (imagesync/logsandinfo/show_error_logs)

 - show_debug_logs (imagesync/logsandinfo/show_debug_logs)

 - show_progress (imagesync/logsandinfo/show_progress)

 - show_total_processed (imagesync/logsandinfo/show_total_processed)

 - show_drive_not_found (imagesync/logsandinfo/show_drive_not_found)

 - show_total_errors (imagesync/logsandinfo/show_total_errors)

 - send_report_email (imagesync/logsandinfo/send_report_email)

 - report_email_address (imagesync/logsandinfo/report_email_address)


## Specifications

 - Cronjob
	- kudos_imagesync_syncimages

 - Helper
	- Kudos\ImageSync\Helper\Data

 - Console Command
	- SyncImages


## Attributes



