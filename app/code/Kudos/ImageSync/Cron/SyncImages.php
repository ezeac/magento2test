<?php
namespace Kudos\ImageSync\Cron;

use Google_Service_Drive;
use Google_Service_Sheets;

class SyncImages
{

    protected $_helper;
    protected $_filesystem;
    protected $_directoryList;
    protected $_client;
    protected $_driveService;
    protected $_sheetService;
    protected $_mediaPath;
    protected $_driveNotFound;
    protected $_productRepository;
    protected $_productModel;
    protected $_galleryReadHandler;
    protected $_scopeConfig;
    protected $_skuCounterOK;
    protected $_skuCounterERROR;
    protected $_counter;

    /**
     * Constructor
     *
     * @param \Kudos\ImageSync\Helper\Data $helper
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Magento\Catalog\Model\Product $productModel
     * @param \Magento\Catalog\Model\Product\Gallery\ReadHandler $galleryReadHandler
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */

    public function __construct(
        \Kudos\ImageSync\Helper\Data $helper,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Model\Product $productModel,
        \Magento\Catalog\Model\Product\Gallery\ReadHandler $galleryReadHandler,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->_helper = $helper;
        $this->_filesystem = $filesystem;
        $this->_directoryList = $directoryList;
        $this->_client = $this->_helper->getClient();
        $this->_mediaPath = $this->_directoryList->getPath('media');
        $this->_driveNotFound = [];
        $this->_productRepository = $productRepository;
        $this->_productModel = $productModel;
        $this->_galleryReadHandler = $galleryReadHandler;
        $this->_scopeConfig = $scopeConfig;
        $this->_skuCounterOK = 0;
        $this->_skuCounterERROR = 0;
        $this->_startTime = time();
    }

    /**
     *
     * @return void
     */
    public function execute()
    {
        if ($this->_client !== false) {
            $this->_driveService = new Google_Service_Drive($this->_client);
            $this->_sheetService = new Google_Service_Sheets($this->_client);
            $this->mainProcess();
            $this->printReports();
            $this->_helper->sendReportEmail();
        } else {
            $this->_helper->printLog("Can't get Google API Client .\n", 'error');
        }
    }

    /**
     * Primary function (read and process google sheets rows)
     *
     * @return void
     */
    protected function mainProcess()
    {
        $this->_helper->printLog("BEGIN:\n" . date('Y-m-d H:i\h\s.') . "\n\n", 'info');

        $spreadsheetId = $this->_scopeConfig->getValue('imagesync/spreadsheets/spreadsheet_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $range = $this->_scopeConfig->getValue('imagesync/spreadsheets/range', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $response = $this->_sheetService->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        if (empty($values)) {
            $this->_helper->printLog("Can't find document data.\n", 'error');
        } else {
            foreach ($values as $index => $row) {
                if (
                    $row[0] !== 'SP' ||
                    !isset($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/sku_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]) ||
                    !isset($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image1_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]) ||
                    !isset($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image2_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]) ||
                    !isset($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image3_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)])
                ) {
                    // skip duplicated and incomplete rows
                    continue;
                }
                if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_info_logs') !== '1' && $this->_scopeConfig->getValue('imagesync/logsandinfo/show_progress') === '1') echo '.';
                $sku = trim($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/sku_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]);
                $rowImages = array(trim($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image1_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]), trim($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image3_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)]), trim($row[$this->_scopeConfig->getValue('imagesync/spreadsheets/image2_column', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)])); // Se invierten el 2do y 3ro para corregir el orden en que se muestran en magento
                $this->_helper->printLog("Line $index, SKU: $sku. Data: '" . $rowImages[0] . "' '" . $rowImages[2] . "' '" . $rowImages[1] . "'.", 'info');

                $totalResult = true;
                $product = $this->_productModel->loadByAttribute('sku', $sku);
                if ($product) {
                    $product->setStoreId(0);
                    $this->_galleryReadHandler->execute($product); // This allow the execution of "getMediaGalleryImages" y "getMediaGalleryEntries"

                    /*
                    To delete images, save thumbnail image and to save the default image, the product must be saved using productRepository
                    But it cant be used to add images ('The image content is not valid'). For adding images will use product->save
                    This is why the product is saved more than 1 time.
                    */

                    /*
                    Next IF -> If the process of search and recover images from Google Drive fails (checkingMissingImagesMagento),
                    Then this product is ignored to avoid saving products without images
                    */

                    $result = $this->checkingMissingImagesMagento($product, $rowImages); // Function to search and recover images from Google Drive
                    if ($result['result']) { // The product is ignored if the process of search and recover images from Google Drive fails


                        $result1 = $this->checkingLeftoverImagesMagento($product, $rowImages); // Delete product images of magento when not matching document images
                        if (!$result1['result']) $this->_helper->printLog($result['description'], 'error');


                        $result2 = $this->checkThumbnailMagentoImage($product, $rowImages); // Update thumbnail and default image of product using the first image of the document
                        if (!$result2['result']) $this->_helper->printLog($result['description'], 'error');


                    } else {
                        $this->_helper->printLog($result['description'], 'error');
                    }

                    if (!($result['result'] && $result1['result'] && $result2['result'])) $totalResult = false; // To count errors and success
                } else {
                    $totalResult = false;
                    $this->_helper->printLog("Cant find SKU on magento: $sku.", 'error');
                }
                ($totalResult) ? $this->_skuCounterOK++ : $this->_skuCounterERROR++;
            }
        }
        $this->_helper->printLog("\n\nDONE\n", 'info');
        $this->_helper->printLog(round((time() - $this->_startTime)/60) . " minutes.\n\n", 'debug');
    }

    /**
     * Search the file on Google Drive (using filter 'like %asd%'), download the first match on media/import and return this image file route
     * 
     * @return array
     */
    protected function recursiveSearchDownloadFromGoogleDrive($name, $retry = 1)
    {
        $result = array('result' => false, 'description' => '');
        $optParams = array(
            'pageSize' => 1,
            'q' => "name contains '$name'",
            'fields' => 'nextPageToken, files(id, name)'
        );
        if (array_search($name, $this->_driveNotFound) !== false) {
            $result = array('result' => false, 'description' => "Cant find image $name on Google Drive");
        } else {
            try {
                $results = $this->_driveService->files->listFiles($optParams);
                if (count($results->getFiles()) > 0) {
                    foreach ($results->getFiles() as $file) {
                        try {
                            $content = $this->_driveService->files->get($file->getId(), array('alt' => 'media' ));
                            $route = 'import/' . $file->getName();
                            $writer = $this->_filesystem->getDirectoryWrite('media');
                            $file = $writer->openFile($route, 'w+');
                            $file->lock();
                            while (!$content->getBody()->eof()) {
                                $file->write($content->getBody()->read(1024));
                            }
                            $result = array('result' => true, 'description' => $route);
                        } catch (\Throwable $th) {
                            $result = array('result' => false, 'description' => "Cant download from google drive $name.");
                            // throw $th;
                        } finally {
                            $file->unlock();
                        }
                    }
                } else {
                    $this->_driveNotFound[] = $name;
                    $result = array('result' => false, 'description' => "Imagen $name not found on Google Drive");
                }
            } catch (\Throwable $th) {
                $result = array('result' => false, 'description' => $th->getMessage());
                if ($retry < 3) {
                    $retry++;
                    $this->_helper->printLog($th->getMessage(), 'debug');
                    $this->_helper->printLog("Cant use Google Drive API. Renewing token and retrying... (retry n° $retry)", 'debug');
                    if ($retry === 3) sleep(60);
                    $this->_client = $this->_helper->getClient();
                    $this->_driveService = new Google_Service_Drive($this->_client);
                    $result = $this->recursiveSearchDownloadFromGoogleDrive($name, $retry);
                }
            }
        }
        return $result;
    }

    /**
     * Delete product images of magento when not matching document images
     * 
     * @return array
     */
    protected function checkingLeftoverImagesMagento($product, $rowImages)
    {
        $this->_helper->printLog('Checking LEFTOVERS images on magento', 'debug');
        try {
            $existingMediaGalleryEntries = $product->getMediaGalleryEntries(); 
        } catch (\Throwable $th) {
            $existingMediaGalleryEntries = [];
        }
        $changed = false;
        $result = array('result' => true, 'description' => '');
        foreach ($existingMediaGalleryEntries as $key1 => $entry) {
            if (array_search($this->_helper->getNameFromUrl($entry->getData('file')), $rowImages) === false) {
                $changed = true;
                unset($existingMediaGalleryEntries[$key1]);
                exec('find ' . $this->_mediaPath . 'catalog/product/ -path "*' . $entry->getData('file') . '*" -exec rm {} \;');
                $this->_helper->printLog('Deleting leftover image on magento: ' . $entry->getData('file'), 'debug');
            }
        }
        if ($changed) {
            try {
                $this->_helper->printLog('Saving ' . count($existingMediaGalleryEntries) . ' images.', 'debug');
                $product->setMediaGalleryEntries($existingMediaGalleryEntries);
                $this->_productRepository->save($product);
            } catch (\Throwable $th) {
                $result = array('result' => false, 'description' => "Error saving product.");
                // throw $th;
            }
        }
        return $result;
    }

    /**
     * Function to search and recover images from Google Drive
     * 
     * @return array
     */
    protected function checkingMissingImagesMagento($product, $rowImages) {
        $this->_helper->printLog('Checking MISSING images on magento', 'debug');
        $existingMediaGalleryEntries = $product->getMediaGalleryEntries(); $changed = false; $toProcess = array();
        $result = array('result' => true, 'description' => '');
        foreach ($rowImages as $key => $image) {
            if (strlen($image) < 4) continue; // Ignoring empty or less than 4 characters image name
            foreach ($existingMediaGalleryEntries as $entry) {
                if ($image === $this->_helper->getNameFromUrl($entry->getData('file')) && is_file($this->_mediaPath . 'catalog/product' . $entry->getData('file'))) {
                    $this->_helper->printLog("Image $image it's OK. BBDD: " . $entry->getData('file'), 'debug');
                    continue 2;
                }
            }
            $newFileImage = $this->recursiveSearchDownloadFromGoogleDrive($image);
            if ($newFileImage['result'] === false) {
                $this->_helper->printLog($newFileImage['description'], 'error');
                // break;
                continue; // Adding images to magento, even when not all were found
            }
    
            $changed = true;
            $this->_helper->printLog("Recovering $image from google drive.", 'debug');
            $delExec = 'find ' . $this->_mediaPath . 'catalog/product/ -path "*' . $image . '.*" -exec rm {} \;'; // delete image file to avoid magento renaming
            $isDefault = ($key === 0) ? array('image', 'thumbnail', 'small_image') : false; // set default thumbnail image
            $toProcess[] = array($delExec, $isDefault, $newFileImage['description']);
        }
        // if ($changed && $result['result']) { // The product is not modified when some images were not found on Google Drive
        if ($changed) { // Adding images to magento, even when not all were found
            try {
                foreach ($toProcess as $value) {
                    exec($value[0]); // delete image file to avoid magento renaming
                    $product->addImageToMediaGallery($value[2], $value[1], false, false);
                }
                $product->save();
                $this->_helper->printLog('New images saved.', 'debug');
                $result = array('result' => true, 'description' => 'New images saved.');
            } catch (\Throwable $th) {
                $result = array('result' => false, 'description' => $th->getMessage());
                // throw $th;
            }
        }
        return $result;
    }

    /**
     * Update thumbnail and default image of product using the first image of the document
     * 
     * @return array
     */
    protected function checkThumbnailMagentoImage($product, $rowImages) {
        $this->_helper->printLog('Checking image THUMBNAIL/DEFAULT on magento', 'debug');
        try {
            $existingMediaGalleryEntries = $product->getMediaGalleryEntries(); 
        } catch (\Throwable $th) {
            $existingMediaGalleryEntries = [];
        }
        $changed = false; $result = array('result' => true, 'description' => '');
        foreach ($existingMediaGalleryEntries as $key1 => $entry) {
            if ($rowImages[0] === $this->_helper->getNameFromUrl($entry->getData('file')) && count($entry->getData('types')) === 0) {
                $changed = true;
                $existingMediaGalleryEntries[$key1]->setTypes(array('image', 'thumbnail', 'small_image'));
            }
        }
        if ($changed) {
            try {
                $product->setMediaGalleryEntries($existingMediaGalleryEntries);
                $this->_productRepository->save($product);
                $this->_helper->printLog('Setting thumbnail default on magento.', 'debug');
            } catch (\Throwable $th) {
                $result = array('result' => false, 'description' => "Error saving product.");
                // throw $th;
            }
        }
        return $result;
    }

    /**
     * @return void
     */
    protected function printReports()
    {
        if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_total_processed', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) === '1') {
            $this->_helper->printLog('Total SKU processed: ' . ($this->_skuCounterERROR + $this->_skuCounterOK) . '. Errors: ' . $this->_skuCounterERROR . '. Success: ' . $this->_skuCounterOK, 'report');
        }

        
        if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_drive_not_found', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) === '1') {
            $this->_helper->printLog('Images not found on Google Drive (' . count($this->_driveNotFound) . '):', 'report');
            $this->_helper->printLog($this->_driveNotFound, 'report');
        }

        
        if ($this->_scopeConfig->getValue('imagesync/logsandinfo/show_total_errors', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) === '1') {
            $this->_helper->reportErrors();
        }
    }
}

