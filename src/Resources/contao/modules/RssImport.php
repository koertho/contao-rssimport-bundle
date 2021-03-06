<?php
/**
 * Contao RSS Import Bundle
 *
 * @copyright 2011, 2014, 2018 agentur fipps e.K.
 * @author    Arne Borchert
 * @package   fipps\contao-rssimport-bundle
 * @license   LGPL 3.0+
 */

namespace Fipps\RssimportBundle;

/**
 * Class RssImport
 */
class RssImport extends \Contao\Backend
{

    private $_iStatsItemsRead;

    private $_iStatsItemsInserted;

    private $_iStatsItemsUpdated;

    private $_sMakeLocalErrorWarning;

    private $_sTable;

    private $_arrEnclosures;

    const TL_NEWS = 'tl_news';

    const TL_NEWS_ARCHIVE = 'tl_news_archive';

    const TL_EVENTS = 'tl_calendar_events';

    const TL_CALENDAR = 'tl_calendar';

    /**
     * Invoke parent constructor
     */
    public function __construct()
    {
        return parent::__construct();
    }

    /**
     * Import all new designated feeds for news, could periodically be called by a Cron-Job
     */
    public function importAllNewsFeeds()
    {
        $this->_sTable = self::TL_NEWS;
        $aNewsArchives = $this->_fetchDatasForFeedimport();

        if (is_array($aNewsArchives)) {
            // Für alle Archive
            foreach ($aNewsArchives as $aNewsArchiveRow) {
                $this->_writeFeed($aNewsArchiveRow);
            }
        }
    }

    /**
     * Callback-Function for updating a specific newsfeed
     *
     * @param \Datacontainer $dc
     */
    public function importNewFeeds(\Datacontainer $dc)
    {
        $this->_sTable = $dc->table;

        $sTable = ($this->_sTable == self::TL_NEWS) ? 'tl_news_archive' : 'tl_calendar';

        $sql = "SELECT $sTable.*, tl_files.path FROM $sTable";
        $sql .= " LEFT JOIN tl_files ON $sTable.rssimp_imgpath LIKE tl_files.uuid";
        $sql .= " WHERE $sTable.id = ? AND $sTable.rssimp_imp = ?";

        if (isset($sql)) {
            $oResult = $this->Database->prepare($sql)->execute($dc->id, '1');
            if ($oResult->numRows > 0) {
                $aRssImportRow = $oResult->fetchAssoc();
                $this->_writeFeed($aRssImportRow);
            }
        }
    }

    /**
     * Callback Function for deleting attachments
     *
     * @param \Datacontainer $dc
     */
    public function deleteAttachments(\Datacontainer $dc)
    {
        $this->_sTable = $dc->table;

        switch ($this->_sTable) {
            case self::TL_NEWS_ARCHIVE:
                $sTable    = 'tl_news';
                $sIdColumn = 'pid';
                break;
            case self::TL_NEWS:
                $sTable    = 'tl_news';
                $sIdColumn = 'id';
                break;
            default:
                $sTable    = false;
                $sIdColumn = false;
                break;
        }

        $sql = "SELECT $sTable.id, tl_files.uuid AS uuid, tl_files.path FROM tl_files";
        $sql .= " LEFT JOIN $sTable ON tl_files.uuid = $sTable.singleSRC";
        $sql .= " WHERE $sTable.$sIdColumn = ?";
        $sql .= " GROUP BY tl_files.id";

        if ($sTable && $sIdColumn) {
            $oResult = $this->Database->prepare($sql)->execute($dc->id);

            if ($oResult->numRows > 0) {
                $aRows = $oResult->fetchAllAssoc();

                if (is_array($aRows)) {
                    foreach ($aRows as $aRow) {
                        if (file_exists(TL_ROOT.'/'.$aRow['path']) && $this->_checkIfAttachmentIsUnused($aRow['id'], $aRow['uuid'], $sTable)) {
                            unlink(TL_ROOT.'/'.$aRow['path']);
                            \Dbafs::deleteResource($aRow['path']);
                        }
                    }
                }
            }
        }
    }

    private function _checkIfAttachmentIsUnused($id, $uuid, $sTable)
    {
        $sql     = "SELECT id FROM $sTable WHERE id != ? AND HEX(singleSRC) = ?";
        $oResult = $this->Database->prepare($sql)->execute($id, bin2hex($uuid));

        return $oResult->numRows == 0;
    }

    /**
     * generate a unique alias
     *
     * @param string $sHeadline
     * @param int    $iId
     * @return string
     */
    private function _generateNewAlias($sHeadline, $iId)
    {
        $sAlias = standardize($sHeadline);
        // Check if alias already exists
        $oResult = $this->Database->prepare("SELECT id FROM $this->_sTable WHERE alias=? ")->execute($sAlias);

        if ($oResult->numRows > 0) {
            $sAlias .= '-'.$iId;
        }

        return $sAlias;
    }

    /**
     * simply returns an empty string if $value is not set
     *
     * @param string $value
     * @return string
     */
    private function _notempty($value)
    {
        return isset($value) ? $value : '';
    }

    /**
     * fetch from tl_news_archive all entries that import a feed
     *
     * @return array
     */
    private function _fetchDatasForFeedimport()
    {
        // $sTable = ($this->_sTable == self::TL_NEWS) ? self::TL_NEWS_ARCHIVE : self::TL_CALENDAR;
        $sTable = self::TL_NEWS_ARCHIVE;
        $sql    = "SELECT $sTable.*, tl_files.path FROM $sTable";
        $sql    .= " LEFT JOIN tl_files ON rssimp_imgpath LIKE uuid";
        $sql    .= " WHERE rssimp_imp = ?";

        if (isset($sql)) {
            $oResult = $this->Database->prepare($sql)->execute(1);
            if ($oResult->numRows) {
                $arRows = $oResult->fetchAllAssoc();

                return $arRows;
            }
        }

        return null;
    }

    /**
     * write feed data for a single archive to tl_news
     *
     * @param array $aNewsArchiveRow
     * @return boolean
     */
    private function _writeFeed($aNewsArchiveRow)
    {
        if ($this->_sTable == self::TL_NEWS) {
            $sPartForLog = "Update News, Archive ID: ".$aNewsArchiveRow['id'];
        }

        // Url ist leer? => return
        if (strlen(trim($aNewsArchiveRow['rssimp_impurl'])) < 1) {
            $this->log($sPartForLog." - Url is empty!", 'RssImport _writefeed', TL_GENERAL);

            return false;
        }
        // initialisiere Werte für Statistik
        $this->_iStatsItemsRead = $this->_iStatsItemsInserted = $this->_iStatsItemsUpdated = 0;
        // lese den Feed
        $oFeed = new FeedChannelModel();
        if (!$oFeed->getFeed($aNewsArchiveRow['rssimp_impurl'])) {
            $this->log($sPartForLog."Could not read Url (".$aNewsArchiveRow['rssimp_impurl'].") ".$oFeed->sError, 'RssImport _writefeed', TL_ERROR);

            return false; // Feed konnte nicht gelesen werden
        }
        $arSimplePieItems = $oFeed->arItems;

        // hole erlaubte tags
        $sAllowedTags = $aNewsArchiveRow['rssimp_allowedTags'];

        // Für alle Beiträge ...
        if ($arSimplePieItems) {

            /** @var FeedItemModel $oSimplePieItem */
            foreach ($arSimplePieItems as $oSimplePieItem) {
                $aTmpArr[]              = $oSimplePieItem;
                $this->_iStatsItemsRead += 1;

                // hole subtitle
                if ($aNewsArchiveRow['rssimp_subtitlesrc']) {
                    switch ($aNewsArchiveRow['rssimp_subtitlesrc']) {
                        case ('category'):
                            if ($oSimplePieItem->arCategoryLabels) {
                                $oSimplePieItem->sSubtitle = implode(', ', $oSimplePieItem->arCategoryLabels);
                            }
                            break;
                        case ('contributor'):
                            $oSimplePieItem->sSubtitle = $oSimplePieItem->sContributorName;
                            break;
                        case ('rights'):
                            $oSimplePieItem->sSubtitle = $oSimplePieItem->sCopyright;
                            break;
                        default:
                            $oSimplePieItem->sSubtitle = '';
                    }
                }

                // get teaser
                $teaser = $this->_notempty($oSimplePieItem->sDescription);
                // convert {space,t,n} to a single space
                $teaser = preg_replace('/\s+/', ' ', $teaser);

                // strip tags
                if ($aNewsArchiveRow['rssimp_teaserhtml'] < 1) {
                    $teaser = strip_tags(html_entity_decode($teaser, ENT_NOQUOTES, $GLOBALS['TL_CONFIG']['characterSet']));
                    // truncate after whole word if html is not allowed
                    if ($aNewsArchiveRow['rssimp_truncate'] > 0 && strlen($teaser) > $aNewsArchiveRow['rssimp_truncate']) {
                        $teaser = wordwrap($teaser, $aNewsArchiveRow['rssimp_truncate'], '#|#');
                        $pos = strpos($teaser, '#|#');
                        $teaser = substr($teaser, 0, $pos).'&hellip;';
                    }
                } else {
                    $teaser = strip_tags(html_entity_decode($teaser, ENT_NOQUOTES, $GLOBALS['TL_CONFIG']['characterSet']), $sAllowedTags);
                }

                $rssimp_source = ($aNewsArchiveRow['rssimp_source'] != 'content') ? $aNewsArchiveRow['rssimp_source'] : 'default';

                if ($this->_sTable == self::TL_NEWS) {
                    // Prepare record for tl_news
                    $aSet = array(
                        // id => auto;
                        'pid'         => $this->_notempty($aNewsArchiveRow['id']),
                        'tstamp'      => $this->_notempty($oSimplePieItem->iUpdated),
                        'headline'    => $this->_notempty($oSimplePieItem->sTitle),
                        'alias'       => '',
                        'author'      => $this->_notempty($aNewsArchiveRow['rssimp_author']),
                        'date'        => $this->_notempty($oSimplePieItem->iPublished),
                        'time'        => $this->_notempty($oSimplePieItem->iPublished),
                        'subheadline' => $this->_notempty($oSimplePieItem->sSubtitle),
                        'teaser'      => $teaser,
                        'singleSRC'   => '',
                        'addImage'    => 0,
                        'imagemargin' => $this->_notempty($aNewsArchiveRow['rssimp_imagemargin']),
                        'size'        => $this->_notempty($aNewsArchiveRow['rssimp_size']),
                        'fullsize'    => $this->_notempty($aNewsArchiveRow['rssimp_fullsize']),
                        'imageUrl'    => $this->_notempty($oSimplePieItem->oImage->sLink),
                        'floating'    => $this->_notempty($aNewsArchiveRow['rssimp_floating']),
                        'enclosure'   => '',
                        'source'      => 'default',
                        'url'         => $this->_notempty($oSimplePieItem->sLink), // Weiterleitungsziel
                        'cssClass'    => $this->_notempty($aNewsArchiveRow['expertdefaults_cssclass']),
                        'published'   => $this->_notempty($aNewsArchiveRow['rssimp_published']),
                        'rssimp_guid' => $this->_notempty($oSimplePieItem->sGuid),
                        'rssimp_link' => $this->_notempty($oSimplePieItem->sLink),
                        'source'      => $rssimp_source,
                        'target'      => $this->_notempty($aNewsArchiveRow['rssimp_target']),
                    );

                    $this->_arrEnclosures = $oSimplePieItem->arrEnclosures;
                }
                if (isset($aSet)) {
                    $_sContent = strip_tags(html_entity_decode($oSimplePieItem->sContent, ENT_NOQUOTES, $GLOBALS['TL_CONFIG']['characterSet']), $sAllowedTags);
                    if ($aNewsArchiveRow['rssimp_source'] == 'content') {
                        $_aLink = array(
                            'titleText' => $oSimplePieItem->sTitle,
                            'url'       => $oSimplePieItem->sLink,
                            'linkTitle' => $GLOBALS['TL_LANG']['MSC']['more'],
                            'target'    => 1,
                        );
                    }

                    $this->_writeSingleItem($aSet, $aNewsArchiveRow, $_sContent, $_aLink);
                } else {
                    return false;
                }
            } // endforeach $arSimplePieItems
        }

        $sLog = "";
        $this->log(
            $sPartForLog.' '.'Rss/Atom-Items found:'.$this->_iStatsItemsRead.' '.'new:'.$this->_iStatsItemsInserted.' '.'updated:'.$this->_iStatsItemsUpdated.' '.'Url:'.$aNewsArchiveRow['rssimp_impurl'],
            'Rssimport->_writefeed',
            TL_GENERAL
        );

        return true;
    }

    /**
     * write single feed item to tl_news
     *
     * @param array  $aSet
     * @param array  $aNewsArchiveRow
     * @param string $sContentLead
     * @param string $sContent
     * @param array  $aLink
     */
    private function _writeSingleItem($aSet, $aRssImportRow, $sContent = null, $aLink = null)
    {
        // Lese parent id
        $iPid = $aRssImportRow['id'];

        // Lese aktuelles Datum (Unix Timestamp) vom Beitrag
        $iItemDate = ($aSet['tstamp'] > $aSet['date']) ? $aSet['tstamp'] : $aSet['date'];

        // Lese id von gelesenem Beitrag
        $sGuid = $aSet['rssimp_guid'];

        // pruefe, ob Beitrag bereits in DB existiert
        $oResult = $this->Database->prepare("SELECT * FROM $this->_sTable WHERE rssimp_guid=? AND pid=? ")->execute($sGuid, $iPid);
        if ($oResult->numRows < 1) {
            // Beitrag existiert noch nicht => sql insert
            $this->_iStatsItemsInserted += 1;
            // neuen Beitrag einfuegen
            $oResult = $this->Database->prepare("INSERT INTO $this->_sTable %s")->set($aSet)->execute();
            $iNewsId = $oResult->insertId; // hole last_insert_id

            // Alias generieren
            $aSet['alias'] = $this->_generateNewAlias($aSet['headline'], $iNewsId);
            // (id hinzufügen)
            // $aSet['alias'] .= '-' . $iNewsId;

            // lokale Kopie für enclosures und image bereitstellen
            $aSet = $this->_makeLocal($aSet, $iNewsId, $aRssImportRow);

            // update tl_news
            $this->Database->prepare("UPDATE $this->_sTable %s WHERE id=? ")->set($aSet)->execute($iNewsId);

            // Content Element generieren, falls vorhanden
            if (isset($sContent) && $sContent != '') {
                $_aContent = array(
                    'pid'     => $iNewsId,
                    'ptable'  => $this->_sTable,
                    'sorting' => 128,
                    'tstamp'  => $aSet['tstamp'],
                    'type'    => 'text',
                    'text'    => $sContent,
                );
                $this->Database->prepare("INSERT INTO tl_content %s")->set($_aContent)->execute();
            }
            if (is_array($aLink)) {
                $aLink['pid']     = $iNewsId;
                $aLink['ptable']  = $this->_sTable;
                $aLink['sorting'] = 256;
                $aLink['tstamp']  = $aSet['tstamp'];
                $aLink['type']    = 'hyperlink';
                $this->Database->prepare("INSERT INTO tl_content %s")->set($aLink)->execute();
            }
        } else {
            // Beitrag existiert, ist aber aktueller => sql update
            $oRow    = $oResult->fetchAssoc(); // lies ersten Datensatz
            $iNewsId = $oRow['id']; // lies id (DS mit selber guid wie Beitrag)
            $iTlDate = ($oRow['tstamp'] > $oRow['date']) ? $oRow['tstamp'] : $oRow['date']; // lies
            // update-Datum
            if ($iTlDate < $iItemDate) {
                // Beitrag ist aktueller?
                $this->_iStatsItemsUpdated += 1;

                // lokale Kopie fuer enclosures löschen
                if ($oRow['addEnclosure']) {
                    $arrEnclosures = deserialize($oRow['enclosure']);
                    foreach ($arrEnclosures as $encUUID) {
                        $objFile = \FilesModel::findByUuid($encUUID);
                        \Dbafs::deleteResource($objFile->path);
                    }
                }

                // Neue lokale Koien der Images und Enclosures
                $aSet = $this->_makeLocal($aSet, $iNewsId, $aRssImportRow);

                // update ausfuehren
                $this->Database->prepare("UPDATE $this->_sTable %s WHERE id=? ")->set($aSet)->execute($iNewsId);

                // Content Element aktualisieren
                if (isset($sContent) && $sContent != '') {
                    $_aContent = array(
                        'pid'     => $iNewsId,
                        'ptable'  => $this->_sTable,
                        'sorting' => 128,
                        'tstamp'  => $aSet['tstamp'],
                        'type'    => 'text',
                        'text'    => $sContent,
                    );
                    $this->Database->prepare("DELETE FROM tl_content WHERE ptable = '?' AND pid = ?")->execute($this->_sTable, $iNewsId);
                    $this->Database->prepare("INSERT INTO tl_content %s")->set($_aContent)->execute();
                }
            }
        }
    }

    /**
     * generate local Url for images and download links (affects the fields imageUrl and singleSrc
     * of tl_news)
     *
     * @param array $aSet
     * @param int   $iItemId
     * @param array $aArchiveRow
     * @return array
     */
    private function _makeLocal($aSet, $iItemId, $aArchiveRow)
    {

        // Image
        if ($aSet['imageUrl'] != '') {
            $uuid              = $this->_storeLocal($aSet['imageUrl'], $aArchiveRow['path'], $iItemId);
            $aSet['singleSRC'] = $uuid;
            $aSet['addImage']  = 1;
        }

        // Anlagen
        $addEnclosure = false;
        foreach ($this->_arrEnclosures as $oEnclosure) {
            $enclosureUrl = $oEnclosure->sLink;
            if ($enclosureUrl == $aSet['imageUrl']) {
                $arrEncUUIDs[] = $uuid;
                $addEnclosure  = true;
            } else if ($encUUID = $this->_storeLocal($enclosureUrl, $aArchiveRow['path'], $iItemId)) {
                $arrEncUUIDs[] = $encUUID;
                $addEnclosure  = true;
            } else {
                $this->log('Warning, cannot make local copy of file('.$enclosureUrl.') reason: '.$this->_sMakeLocalErrorWarning, 'RssImport->_makelocal', TL_ERROR);
            }
        }

        if ($addEnclosure) {
            $aSet['enclosure']    = serialize($arrEncUUIDs);
            $aSet['addEnclosure'] = 1;
        }

        return $aSet;
    }

    /**
     * provides archive with local copies of external download/image files
     *
     * @param string $sExtUrl
     * @param string $sLocalPath
     * @param int    $iId
     * @return string
     */
    private function _storeLocal($sExtUrl, $sLocalPath, $iId)
    {
        // reset warning message
        $this->_sMakeLocalErrorWarning = '';

        // Positivliste für Datei-Extensions
        $sAllowedSuffixes = $GLOBALS['TL_CONFIG']['allowedDownload'];

        if (strlen($sExtUrl) == 0) // Leerstring als ext. URL ist sinnlos
        {
            $this->_sMakeLocalErrorWarning .= ' empty URL not allowed';
        }

        if (strlen($sLocalPath) < 2) // dulde keinen Leerstring als Basispfad
        {
            $this->_sMakeLocalErrorWarning .= ' empty basepath for downloads not allowed';
        }

        // setze lokalen Dateinamen: sLocalPath + filename + _ + id + extension
        $arInfo              = pathinfo($sExtUrl);
        $arExtension         = explode('?', $arInfo['extension']);
        $arInfo['extension'] = strtolower($arExtension[0]); // hole suffix;
        $sFilename           = standardize(basename($sExtUrl, '.'.$arInfo['extension'])); // hole dateinamen
        // (ohne suffix)
        // $sLocalFilename = $sFilename . '_' . $iId . '.' .
        // $arInfo['extension'];
        $sLocalfile = $sLocalPath.'/'.$sFilename.'_'.$iId.'.'.$arInfo['extension'];

        if (!in_array($arInfo['extension'], trimsplit(',', strtolower($sAllowedSuffixes)))) {
            $this->_sMakeLocalErrorWarning .= ' Suffix not supported ';
        }

        //         if (strpos($sExtUrl, '?') !== false)
        //             $this->_sMakeLocalErrorWarning .= ' special char in url not allowed (' . $sExtUrl . ')';

        if (file_exists(TL_ROOT.'/'.$sLocalfile)) {
            $this->_sMakeLocalErrorWarning .= ' output file alrady exists ';
        }

        if (strlen($this->_sMakeLocalErrorWarning) > 0) {
            return null; // Abbruch
        }

        // read
        try {
            $url   = $arInfo['dirname'].'/'.urlencode($arInfo['basename']);
            $sData = file_get_contents($url);
        } catch (Exception $oException) {
            $this->_sMakeLocalErrorWarning .= ' could not read from url('.$oException->getMessage().')';

            return null; // Abbruch
        }

        if (strlen($sData) <= 0) {
            $this->_sMakeLocalErrorWarning .= ' no file data('.$sExtUrl.')';

            return null; // Abbruch
        }

        // write
        try {
            file_put_contents(TL_ROOT.'/'.$sLocalfile, $sData);
            $objModel = \Dbafs::addResource($sLocalfile);
        } catch (Exception $oException) {
            $this->_sMakeLocalErrorWarning .= ' could not write file('.$oException->getMessage().')';

            return null; // Abbruch
        }

        return $objModel->uuid; // Erfolg
    }
}
