<?php
/**
 * BatchController.php - Main Controller
 *
 * Batch Controller Faucet Module
 *
 * @category Controller
 * @package Faucet\Batch
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

namespace OnePlace\Faucet\Batch\Controller;

use Application\Controller\CoreEntityController;
use Application\Model\CoreEntityModel;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Http\ClientStatic;
use OnePlace\User\Model\UserTable;

class BatchController extends CoreEntityController
{
    /**
     * Faucet Table Object
     *
     * @since 1.0.0
     */
    protected $oTableGateway;

    /**
     * FaucetController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param FaucetTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,UserTable $oTableGateway,$oServiceManager)
    {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'faucet-batch-single';

        if(isset(CoreEntityController::$oSession->oUser)) {
            setlocale(LC_TIME, CoreEntityController::$oSession->oUser->lang);
        }

        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    /**
     * Placeholder Welcome Batch
     *
     * @return mixed
     * @since 1.0.0
     */
    public function indexAction()
    {
        $this->layout('layout/json');

        return $this->redirect()->toRoute('home');
    }

    /**
     * Generate Stats for Hall of Fame
     *
     * @return false
     * @since 1.0.0
     */
    public function halloffameAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != 'SERVERBATCH') {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oStatsTbl = $this->getCustomTable('faucet_statistic');
        $oAdsTbl = $this->getCustomTable('ptc');
        $oAdsDoneTbl = $this->getCustomTable('ptc_user');
        $oProvidersTbl = $this->getCustomTable('shortlink');
        $oMyLinksDoneTbl = $this->getCustomTable('shortlink_link_user');
        $oWallsDoneTbl = $this->getCustomTable('offerwall_user');
        $oClaimTbl = $this->getCustomTable('faucet_claim');
        $oGameTbl = $this->getCustomTable('faucet_game_match');
        $oMinerTbl = $this->getCustomTable('faucet_miner');

        $oWh = new Where();
        $oWh->greaterThan('xp_level', 1);
        $aUsers = $this->fetchCustomTable('user', $oWh);


        $aSkipUsers = ['335874987' => true];
        $aUsersByIncome = [];
        $aUsersByID = [];
        foreach($aUsers as $oUsr) {
            if(array_key_exists($oUsr->User_ID,$aSkipUsers)) {
                continue;
            }
            $fTotalIncome = 0;

            $oWh = new Where();
            $oWh->equalTo('user_idfs', $oUsr->User_ID);

            $oPtcsDone = $oAdsDoneTbl->select($oWh);
            if(count($oPtcsDone) > 0) {
                foreach($oPtcsDone as $oPtcD) {
                    $oPtc = $oAdsTbl->select(['PTC_ID' => $oPtcD->ptc_idfs])->current();
                    $fTotalIncome+=$oPtc->reward;
                }
            }

            $oShortsDone = $oMyLinksDoneTbl->select($oWh);
            if(count($oShortsDone) > 0) {
                foreach($oShortsDone as $oShD) {
                    $oSh = $oProvidersTbl->select(['Shortlink_ID' => $oShD->shortlink_idfs])->current();
                    $fTotalIncome+=$oSh->reward;
                }
            }

            $oWallsDone = $oWallsDoneTbl->select($oWh);
            if(count($oWallsDone) > 0) {
                foreach($oWallsDone as $oWallD) {
                    $fTotalIncome+=$oWallD->amount;
                }
            }

            $oClaimsDone = $oClaimTbl->select($oWh);
            if(count($oClaimsDone) > 0) {
                foreach($oClaimsDone as $oClaimD) {
                    if($oClaimD->mode == 'coins') {
                        $fTotalIncome+=$oClaimD->amount;
                    }
                }
            }

            $oWhWin = new Where();
            $oWhWin->equalTo('winner_idfs', $oUsr->User_ID);
            $oGamesWon = $oGameTbl->select($oWhWin);
            if(count($oGamesWon) > 0) {
                foreach($oGamesWon as $oGameD) {
                    $fTotalIncome+=$oGameD->amount_bet;
                }
            }

            $oMinerShares = $oMinerTbl->select($oWh);
            if(count($oMinerShares) > 0) {
                foreach($oMinerShares as $oShare) {
                    $fTotalIncome+=$oShare->amount_coin;
                }
            }

            $aUsersByIncome[$oUsr->User_ID] = $fTotalIncome;
            $aUsersByID[$oUsr->User_ID] = $oUsr->username;
        }

        arsort($aUsersByIncome);

        $aTopEarners = [];

        $iCount = 1;
        foreach(array_keys($aUsersByIncome) as $iUsrID) {
            if($iCount == 6) {
                break;
            }
            $aTopEarners[$iCount] = (object)['id' => $iUsrID,'coins' => $aUsersByIncome[$iUsrID]];
            $iCount++;
        }

        $oCheckWh = new Where();
        $oCheckWh->like('stat-key', 'topearners-daily');
        $oCheckWh->like('date', date('Y-m-d', time()).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stat-key' => 'topearners-daily',
                'date' => date('Y-m-d H:i:s', time()),
                'stat-data' => json_encode($aTopEarners),
            ]);
        } else {
            $oStatsTbl->update([
                'stat-data' => json_encode($aTopEarners),
            ],$oCheckWh);
        }

        echo 'Gen Stats';

        return false;
    }

    /**
     * Get Balances from Nanopool and Credit Coins
     *
     * @return false
     * @since 1.0.0
     */
    public function getminerbalancesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != 'SERVERBATCH') {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sApiINfo = file_get_contents(CoreEntityController::$aGlobalSettings['miner-pool-url']);
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');

        if(isset($oApiData->data)) {
            $fTotalHash = 0;
            foreach($oApiData->data as $oW) {
                $bIsFaucetMiner = stripos($oW->id,'swissfaucetio');
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->id,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $iCurrentShares = $oW->rating;

                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->like('coin', 'rvn');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        $fCoins = 0;
                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $fCoins = round((float)($iCurrentShares/700)*2000,2);
                            $oMinerTbl->insert([
                                'user_idfs' => $oMinerUser->getID(),
                                'rating' => $iCurrentShares,
                                'shares' => $iCurrentShares,
                                'amount_coin' => $fCoins,
                                'date' => date('Y-m-d H:i:s', time()),
                                'coin' => 'rvn',
                                'pool' => 'nanopool',
                            ]);
                            echo 'miner added';
                        } else {
                            $oLastEntry = $oLastEntry->current();
                            $iNewShares = $iCurrentShares-$oLastEntry->rating;
                            $fCoins = round((float)($iNewShares/700)*2000,2);

                            $oMinerTbl->insert([
                                'user_idfs' => $oMinerUser->getID(),
                                'rating' => $iCurrentShares,
                                'shares' => $iNewShares,
                                'amount_coin' => $fCoins,
                                'date' => date('Y-m-d H:i:s', time()),
                                'coin' => 'rvn',
                                'pool' => 'nanopool',
                            ]);

                            echo 'miner updated';
                        }
                        $fTotalHash+=$oW->hashrate;
                        if($fCoins > 0) {
                            $fCurrentBalance = $oUsrTbl->select(['User_ID' => $oMinerUser->getID()])->current()->token_balance;
                            $oUsrTbl->update([
                                'token_balance' => $fCurrentBalance+$fCoins,
                            ],'User_ID = '.$oMinerUser->getID());
                        }
                    }
                }
            }

            $oSetTbl = $this->getCustomTable('settings');
            $oSetTbl->update(['settings_value' => round($fTotalHash,2)],['settings_key' => 'faucetminer-totalhash']);
        }

        return false;
    }


}
