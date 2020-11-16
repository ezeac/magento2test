<?php
namespace Kudos\ImageSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Sheets;

class Data extends AbstractHelper
{

    protected $_logger;
    protected $_scopeConfig;
    protected $_credentialsPath;
    protected $_dir;
    protected $_filesystem;
    protected $_errorCount;
    protected $_logs;
    protected $_transportBuilder;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Filesystem\DirectoryList $dir
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem\DirectoryList $dir,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder
    ) {
        $this->_logger = $logger;
        $this->_scopeConfig = $scopeConfig;
        $this->_dir = $dir;
        $this->_filesystem = $filesystem;
        $this->_errorCount = [];
        $this->_logs = '';
        $this->_credentialsPath = '/tmp/Kudos_ImageSync_Credentials.json';
        $this->_transportBuilder = $transportBuilder;

        parent::__construct($context);
    }

    /**
     * @return bool
     */
    protected function reloadCredentials()
    {
        $return = true;
        $credentials = '{
            "installed":{
                "client_id":"' . $this->_scopeConfig->getValue('imagesync/googleapicredentials/client_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) . '",
                "project_id":"' . $this->_scopeConfig->getValue('imagesync/googleapicredentials/project_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) . '",
                "auth_uri":"https://accounts.google.com/o/oauth2/auth",
                "token_uri":"https://oauth2.googleapis.com/token",
                "auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs",
                "client_secret":"' . $this->_scopeConfig->getValue('imagesync/googleapicredentials/client_secret', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) . '",
                "redirect_uris":[
                    "urn:ietf:wg:oauth:2.0:oob",
                    "http://localhost"
                ]
            }
        }';

        try {
            $writer = $this->_filesystem->getDirectoryWrite('var');
            $file = $writer->openFile($this->_credentialsPath, 'w');
            $file->lock();
            $file->write($credentials);
        } catch(Exception $e) {
            $return = false;
            echo $e->getMessage();
        } finally {
            $file->unlock();
        }

        return $return;
    }

    /**
     * @return bool
     */
    public function getClient() // Google API connection
    {
        if ($this->reloadCredentials() === false) { return false; }
        $client = new Google_Client();
        $client->setApplicationName('Google Sheets API PHP Quickstart');
        $client->setScopes(array(Google_Service_Sheets::SPREADSHEETS_READONLY, Google_Service_Drive::DRIVE_READONLY));
        $client->setAuthConfig($this->_dir->getPath('var') . $this->_credentialsPath);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
    
        $tokenPath = 'token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }
    
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));
    
                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);
    
                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }

    /**
     * Managing logs and messages during execution
     * 
     * @return void
     */
    public function printLog($msj, $type = '')
    {
        if (!is_string($msj)) $msj = print_r($msj, true);
        $msj = $type === '' ? $msj : "$type - $msj";
        $type = strtolower($type);

        switch ($type) {
            case '':
                echo "$msj\n";
                $this->_logger->info($msj);
                $this->_logs .= "$msj\n";
                break;
            case 'report':
                echo "$msj\n";
                $this->_logger->info($msj);
                $this->_logs .= "$msj\n";
                break;
            case 'info':
                if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_info_logs') === '1') {
                    echo "$msj\n";
                    $this->_logger->info($msj);
                    $this->_logs .= "$msj\n";
                }
                break;
            case 'error':
                $this->_errorCount[] = $msj;
                if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_error_logs') === '1') {
                    echo "$msj\n";
                    $this->_logger->error($msj);
                    $this->_logs .= "$msj\n";
                }
                break;
            case 'debug':
                if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_debug_logs') === '1') {
                    echo "$msj\n";
                    $this->_logger->debug($msj);
                    $this->_logs .= "$msj\n";
                }
                break;
            default:
                echo "$msj\n";
                $this->_logger->$type($msj);
                $this->_logs .= "$msj\n";
                break;
        }
    }

    /**
     * Format url, return clean filename without extension
     * 
     * @return string
     */
    public function getNameFromUrl($url)
    {
        $imageName = false; $namePos = strrpos($url, '/', -1);
        if ($namePos !== false) {
            $nameWithExtension = substr($url, $namePos + 1);
            $dotPos = strrpos($nameWithExtension, '.', -1);
            if ($dotPos !== false) {
                $imageName = substr($nameWithExtension, 0, $dotPos);
            }
        }
        return $imageName;
    }

    /**
     * Print total errors report
     * 
     * @return void
     */
    public function reportErrors()
    {
        $this->printLog('Total errors (' . count($this->_errorCount) . '):', 'report');
        $this->printLog($this->_errorCount, 'report');
    }

    /**
     * Send all process messages to saved email (imagesync/logsandinfo/report_email_address)
     * 
     * @return void
     */
    public function sendReportEmail()
    {
        if ($this->_scopeConfig->getValue('imagesync/logsandinfo/send_report_email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) === '1')
        {
            $senderName = $this->_scopeConfig->getValue('trans_email/ident_sales/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $senderEmail = $this->_scopeConfig->getValue('trans_email/ident_sales/email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $to = $this->_scopeConfig->getValue('imagesync/logsandinfo/report_email_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

            $transport = $this->_transportBuilder->setTemplateIdentifier('sync_images_report')
                ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID])
                ->setTemplateVars(['msj' => $this->_logs])
                ->setFrom(['name' => $senderName, 'email' => $senderEmail])
                ->addTo($to)
                ->setReplyTo($senderEmail)            
                ->getTransport();

            $transport->sendMessage();
        }
    }
}

