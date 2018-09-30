<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      https://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Modules\Web\Controllers\Helpers\Grid;

use SP\Core\Acl\Acl;
use SP\Core\Acl\ActionsInterface;
use SP\Html\DataGrid\DataGridAction;
use SP\Html\DataGrid\DataGridActionSearch;
use SP\Html\DataGrid\DataGridActionType;
use SP\Html\DataGrid\DataGridData;
use SP\Html\DataGrid\DataGridHeader;
use SP\Html\DataGrid\DataGridInterface;
use SP\Html\DataGrid\DataGridTab;
use SP\Http\Address;
use SP\Storage\Database\QueryResult;

/**
 * Class TrackGrid
 *
 * @package SP\Modules\Web\Controllers\Helpers\Grid
 */
final class TrackGrid extends GridBase
{
    /**
     * @var QueryResult
     */
    private $queryResult;

    /**
     * @param QueryResult $queryResult
     *
     * @return DataGridInterface
     */
    public function getGrid(QueryResult $queryResult): DataGridInterface
    {
        $this->queryResult = $queryResult;

        $grid = $this->getGridLayout();

        $searchAction = $this->getSearchAction();

        $grid->setDataActions($searchAction);
        $grid->setPager($this->getPager($searchAction));

        $grid->setDataActions($this->getRefrestAction());
        $grid->setDataActions($this->getClearAction());
        $grid->setDataActions($this->getUnlockAction());

        $grid->setTime(round(getElapsedTime($this->queryTimeStart), 5));

        return $grid;
    }

    /**
     * @return DataGridInterface
     */
    protected function getGridLayout(): DataGridInterface
    {
        // Grid
        $gridTab = new DataGridTab($this->view->getTheme());
        $gridTab->setId('tblTracks');
        $gridTab->setDataRowTemplate('datagrid-rows', 'grid');
        $gridTab->setDataPagerTemplate('datagrid-nav-full', 'grid');
        $gridTab->setHeader($this->getHeader());
        $gridTab->setData($this->getData());
        $gridTab->setTitle(__('Tracks'));

        return $gridTab;
    }

    /**
     * @return DataGridHeader
     */
    protected function getHeader(): DataGridHeader
    {
        // Grid Header
        $gridHeader = new DataGridHeader();
        $gridHeader->addHeader(__('Fecha'));
        $gridHeader->addHeader(__('Fecha Desbloqueo'));
        $gridHeader->addHeader(__('Origen'));
        $gridHeader->addHeader('IPv4');
        $gridHeader->addHeader('IPv6');
        $gridHeader->addHeader(__('Usuario'));

        return $gridHeader;
    }

    /**
     * @return DataGridData
     */
    protected function getData(): DataGridData
    {
        // Grid Data
        $gridData = new DataGridData();
        $gridData->setDataRowSourceId('id');
        $gridData->addDataRowSource('dateTime');
        $gridData->addDataRowSource('dateTimeUnlock');
        $gridData->addDataRowSource('source');
        $gridData->addDataRowSource('ipv4', null, function ($value) {
            return $value !== null ? Address::fromBinary($value) : '';
        });
        $gridData->addDataRowSource('ipv6', null, function ($value) {
            return $value !== null ? Address::fromBinary($value) : '';
        });
        $gridData->addDataRowSource('userId');
        $gridData->setData($this->queryResult);

        return $gridData;
    }

    /**
     * @return DataGridActionSearch
     */
    private function getSearchAction()
    {
        // Grid Actions
        $gridActionSearch = new DataGridActionSearch();
        $gridActionSearch->setId(ActionsInterface::TRACK_SEARCH);
        $gridActionSearch->setType(DataGridActionType::SEARCH_ITEM);
        $gridActionSearch->setName('frmSearchTrack');
        $gridActionSearch->setTitle(__('Buscar Track'));
        $gridActionSearch->setOnSubmitFunction('appMgmt/search');
        $gridActionSearch->addData('action-route', Acl::getActionRoute(ActionsInterface::TRACK_SEARCH));

        return $gridActionSearch;
    }

    /**
     * @return DataGridAction
     */
    private function getRefrestAction()
    {
        $gridAction = new DataGridAction();
        $gridAction->setId(ActionsInterface::TRACK_SEARCH);
        $gridAction->setType(DataGridActionType::MENUBAR_ITEM);
        $gridAction->setSkip(true);
        $gridAction->setName(__('Refrescar'));
        $gridAction->setTitle(__('Refrescar'));
        $gridAction->setIcon($this->icons->getIconRefresh());
        $gridAction->setOnClickFunction('appMgmt/search');
        $gridAction->addData('action-route', Acl::getActionRoute(ActionsInterface::TRACK_SEARCH));

        return $gridAction;
    }

    /**
     * @return DataGridAction
     */
    private function getClearAction()
    {
        $gridAction = new DataGridAction();
        $gridAction->setId(ActionsInterface::TRACK_CLEAR);
        $gridAction->setType(DataGridActionType::MENUBAR_ITEM);
        $gridAction->setSkip(true);
        $gridAction->setName(Acl::getActionInfo(ActionsInterface::TRACK_CLEAR));
        $gridAction->setTitle(Acl::getActionInfo(ActionsInterface::TRACK_CLEAR));
        $gridAction->setIcon($this->icons->getIconClear());
        $gridAction->setOnClickFunction('track/clear');
        $gridAction->addData('action-route', Acl::getActionRoute(ActionsInterface::TRACK_CLEAR));

        return $gridAction;
    }

    /**
     * @return DataGridAction
     */
    private function getUnlockAction()
    {
        $gridAction = new DataGridAction();
        $gridAction->setId(ActionsInterface::TRACK_UNLOCK);
        $gridAction->setType(DataGridActionType::EDIT_ITEM);
        $gridAction->setName(Acl::getActionInfo(ActionsInterface::TRACK_UNLOCK));
        $gridAction->setTitle(Acl::getActionInfo(ActionsInterface::TRACK_UNLOCK));
        $gridAction->setIcon($this->icons->getIconCheck());
        $gridAction->setOnClickFunction('track/unlock');
        $gridAction->addData('action-route', Acl::getActionRoute(ActionsInterface::TRACK_UNLOCK));
        $gridAction->setFilterRowSource('tracked', 0);

        return $gridAction;
    }
}