<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


/**
 * System information widget view.
 *
 * @var CView $this
 * @var array $data
 */

switch ($data['info_type']) {
	case ZBX_SYSTEM_INFO_SERVER_STATS:
		$body = (new CPartial('administration.system.info', [
			'system_info' => $data['system_info'],
			'show_software_update_check_details' => array_key_exists('show_software_update_check_details', $data)
				&& $data['show_software_update_check_details'],
			'user_type' => $data['user_type']
		]))->getOutput();
		break;

	case ZBX_SYSTEM_INFO_HAC_STATUS:
		if ($data['user_type'] == USER_TYPE_SUPER_ADMIN) {
			$body = (new CPartial('administration.ha.nodes', [
				'ha_nodes' => $data['system_info']['ha_nodes'],
				'ha_cluster_enabled' => $data['system_info']['ha_cluster_enabled'],
				'failover_delay' => $data['system_info']['failover_delay']
			]))->getOutput();
		}
		else {
			$body = (new CTableInfo())
				->setNoDataMessage(_('No permissions to referred object or it does not exist!'))
				->toString();
		}
		break;

	default:
		$body = '';
}

(new CWidgetView($data))
	->addItem($body)
	->show();
