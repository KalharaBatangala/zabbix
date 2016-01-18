<?php
/*
** Zabbix
** Copyright (C) 2001-2015 Zabbix SIA
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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/screens.inc.php';

$page['title'] = _('Configuration of slide shows');
$page['file'] = 'slideconf.php';
$page['type'] = detect_page_type(PAGE_TYPE_HTML);
$page['scripts'] = ['multiselect.js'];

require_once dirname(__FILE__).'/include/page_header.php';

//	VAR		TYPE	OPTIONAL FLAGS	VALIDATION	EXCEPTION
$fields = [
	'shows' =>			[T_ZBX_INT, O_OPT,	P_SYS,		DB_ID,	null],
	'slideshowid' =>	[T_ZBX_INT, O_NO,	P_SYS,		DB_ID,	'isset({form}) && {form} == "update"'],
	'name' => [T_ZBX_STR, O_OPT, null, NOT_EMPTY, 'isset({add}) || isset({update})', _('Name')],
	'delay' => [T_ZBX_INT, O_OPT, null, BETWEEN(1, SEC_PER_DAY), 'isset({add}) || isset({update})',_('Default delay (in seconds)')],
	'slides' =>			[null,		 O_OPT, null,		null,	null],
	'userid' =>			[T_ZBX_INT, O_OPT, P_SYS,	DB_ID,			null],
	'private' =>		[T_ZBX_INT, O_OPT, null,	BETWEEN(0, 1),	null],
	'users' =>			[T_ZBX_INT, O_OPT, null,	null,			null],
	'userGroups' =>		[T_ZBX_INT, O_OPT, null,	null,			null],
	// actions
	'action' =>			[T_ZBX_STR, O_OPT, P_SYS|P_ACT, IN('"slideshow.massdelete"'),	null],
	'clone' =>			[T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null],
	'add' =>			[T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null],
	'update' =>			[T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null],
	'delete' =>			[T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null],
	'cancel' =>			[T_ZBX_STR, O_OPT, P_SYS,		null,	null],
	'form' =>			[T_ZBX_STR, O_OPT, P_SYS,		null,	null],
	'form_refresh' =>	[T_ZBX_INT, O_OPT, null,		null,	null],
	// sort and sortorder
	'sort' =>			[T_ZBX_STR, O_OPT, P_SYS, IN('"cnt","delay","name"'),					null],
	'sortorder' =>		[T_ZBX_STR, O_OPT, P_SYS, IN('"'.ZBX_SORT_DOWN.'","'.ZBX_SORT_UP.'"'),	null]
];
check_fields($fields);

if (!empty($_REQUEST['slides'])) {
	natksort($_REQUEST['slides']);
}

/*
 * Permissions
 */
if (hasRequest('slideshowid')) {
	if (!slideshow_accessible($_REQUEST['slideshowid'], PERM_READ_WRITE)) {
		access_deny();
	}

	$db_slideshow = get_slideshow_by_slideshowid(getRequest('slideshowid'));

	if (!$db_slideshow) {
		access_deny();
	}
}
if (hasRequest('action')) {
	if (!hasRequest('shows') || !is_array(getRequest('shows'))) {
		access_deny();
	}
	else {
		$dbSlideshowCount = DBfetch(DBselect(
			'SELECT COUNT(*) AS cnt FROM slideshows s WHERE '.dbConditionInt('s.slideshowid', getRequest('shows'))
		));

		if ($dbSlideshowCount['cnt'] != count(getRequest('shows'))) {
			access_deny();
		}
	}
}

/*
 * Actions
 */
if (isset($_REQUEST['clone']) && isset($_REQUEST['slideshowid'])) {
	unset($_REQUEST['slideshowid']);
	$_REQUEST['form'] = 'clone';
}
elseif (hasRequest('add') || hasRequest('update')) {
	DBstart();

	if (hasRequest('update')) {
		$result = update_slideshow(getRequest('slideshowid'), getRequest('name'), getRequest('delay'), getRequest('slides', []));

		$messageSuccess = _('Slide show updated');
		$messageFailed = _('Cannot update slide show');
		$auditAction = AUDIT_ACTION_UPDATE;
	}
	else {
		$result = add_slideshow(getRequest('name'), getRequest('delay'), getRequest('slides', []));

		$messageSuccess = _('Slide show added');
		$messageFailed = _('Cannot add slide show');
		$auditAction = AUDIT_ACTION_ADD;
	}

	if ($result) {
		add_audit($auditAction, AUDIT_RESOURCE_SLIDESHOW, ' Name "'.getRequest('name').'" ');
		unset($_REQUEST['form'], $_REQUEST['slideshowid']);
	}

	$result = DBend($result);

	if ($result) {
		uncheckTableRows();
	}
	show_messages($result, $messageSuccess, $messageFailed);
}
elseif (isset($_REQUEST['delete']) && isset($_REQUEST['slideshowid'])) {
	DBstart();

	$result = delete_slideshow($_REQUEST['slideshowid']);

	if ($result) {
		add_audit(AUDIT_ACTION_DELETE, AUDIT_RESOURCE_SLIDESHOW, ' Name "'.$db_slideshow['name'].'" ');
	}
	unset($_REQUEST['slideshowid'], $_REQUEST['form']);

	$result = DBend($result);

	if ($result) {
		uncheckTableRows();
	}
	show_messages($result, _('Slide show deleted'), _('Cannot delete slide show'));
}
elseif (hasRequest('action') && getRequest('action') == 'slideshow.massdelete' && hasRequest('shows')) {
	$result = true;

	$shows = getRequest('shows');
	DBstart();

	foreach ($shows as $showid) {
		$result &= delete_slideshow($showid);
		if (!$result) {
			break;
		}
	}

	$result = DBend($result);

	if ($result) {
		unset($_REQUEST['form']);
		uncheckTableRows();
	}
	show_messages($result, _('Slide show deleted'), _('Cannot delete slide show'));
}

/*
 * Display
 */
if (isset($_REQUEST['form'])) {
	$current_userid = CWebUser::$data['userid'];
	$userids[$current_userid] = true;
	$user_groupids = [];

	$data = [
		'form' => getRequest('form'),
		'form_refresh' => getRequest('form_refresh', 0)
	];

	if (!hasRequest('slideshowid') || hasRequest('form_refresh')) {
		// Slide show owner.
		$slideshow_owner = getRequest('userid', $current_userid);
		$userids[$slideshow_owner] = true;

		foreach (getRequest('users', []) as $user) {
			$userids[$user['userid']] = true;
		}

		foreach (getRequest('userGroups', []) as $user_group) {
			$user_groupids[$user_group['usrgrpid']] = true;
		}
	}
	else {
		// Slide show owner.
		$userids[$db_slideshow['userid']] = true;

		$db_slideshow['users'] = DBfetchArray(DBselect(
			'SELECT s.userid,s.permission'.
			' FROM slideshow_user s'.
			' WHERE s.slideshowid='.zbx_dbstr(getRequest('slideshowid'))
		));

		foreach ($db_slideshow['users'] as $user) {
			$userids[$user['userid']] = true;
		}

		$db_slideshow['userGroups'] = DBfetchArray(DBselect(
			'SELECT s.usrgrpid,s.permission'.
			' FROM slideshow_usrgrp s'.
			' WHERE s.slideshowid='.zbx_dbstr(getRequest('slideshowid'))
		));

		foreach ($db_slideshow['userGroups'] as $user_group) {
			$user_groupids[$user_group['usrgrpid']] = true;
		}
	}

	$data['users'] = API::User()->get([
		'output' => ['userid', 'alias', 'name', 'surname'],
		'userids' => array_keys($userids),
		'preservekeys' => true
	]);

	$data['user_groups'] = API::UserGroup()->get([
		'output' => ['usrgrpid', 'name'],
		'usrgrpids' => array_keys($user_groupids),
		'preservekeys' => true
	]);

	if (isset($data['slideshowid']) && !isset($_REQUEST['form_refresh'])) {
		$data['slideshow'] = [
			'slideshowid' => $db_slideshow['slideshowid'],
			'name' => $db_slideshow['name'],
			'delay' => $db_slideshow['delay'],
			'userid' => $db_slideshow['userid'],
			'private' => $db_slideshow['private'],
			'users' => $db_slideshow['users'],
			'userGroups' => $db_slideshow['userGroups']
		];

		// Get slides.
		$data['slides'] = DBfetchArray(DBselect(
				'SELECT s.slideid, s.screenid, s.delay'.
				' FROM slides s'.
				' WHERE s.slideshowid='.zbx_dbstr($data['slideshowid']).
				' ORDER BY s.step'
		));
	}
	else {
		$data['slideshow'] = [
			'slideshowid' => getRequest('slideshowid'),
			'name' => getRequest('name', ''),
			'delay' => getRequest('delay', ZBX_ITEM_DELAY_DEFAULT),
			'slides' => getRequest('slides', []),
			'userid' => getRequest('userid', hasRequest('form_refresh') ? '' : $current_userid),
			'private' => getRequest('private', 1),
			'users' => getRequest('users', []),
			'userGroups' => getRequest('userGroups', [])
		];
	}

	$data['current_user_userid'] = $current_userid;

	// Get slides without delay.
	$data['slides_without_delay'] = $data['slideshow']['slides'];
	foreach ($data['slides_without_delay'] as &$slide) {
		unset($slide['delay']);
	}
	unset($slide);

	// render view
	$slideshowView = new CView('monitoring.slideconf.edit', $data);
	$slideshowView->render();
	$slideshowView->show();
}
else {
	$sortField = getRequest('sort', CProfile::get('web.'.$page['file'].'.sort', 'name'));
	$sortOrder = getRequest('sortorder', CProfile::get('web.'.$page['file'].'.sortorder', ZBX_SORT_UP));

	CProfile::update('web.'.$page['file'].'.sort', $sortField, PROFILE_TYPE_STR);
	CProfile::update('web.'.$page['file'].'.sortorder', $sortOrder, PROFILE_TYPE_STR);

	$config = select_config();
	$limit = $config['search_limit'] + 1;

	$data = [
		'sort' => $sortField,
		'sortorder' => $sortOrder
	];

	$data['slides'] = DBfetchArray(DBselect(
			'SELECT s.slideshowid,s.name,s.delay,COUNT(sl.slideshowid) AS cnt'.
			' FROM slideshows s'.
				' LEFT JOIN slides sl ON sl.slideshowid=s.slideshowid'.
			' GROUP BY s.slideshowid,s.name,s.delay'.
			' ORDER BY '.(($sortField === 'cnt') ? 'cnt' : 's.'.$sortField)
	));

	foreach ($data['slides'] as $key => $slide) {
		if (!slideshow_accessible($slide['slideshowid'], PERM_READ_WRITE)) {
			unset($data['slides'][$key]);
		}
	}

	order_result($data['slides'], $sortField, $sortOrder);

	if ($sortOrder == ZBX_SORT_UP) {
		$data['slides'] = array_slice($data['slides'], 0, $limit);
	}
	else {
		$data['slides'] = array_slice($data['slides'], -$limit, $limit);
	}

	order_result($data['slides'], $sortField, $sortOrder);

	$data['paging'] = getPagingLine($data['slides'], $sortOrder);

	// render view
	$slideshowView = new CView('monitoring.slideconf.list', $data);
	$slideshowView->render();
	$slideshowView->show();
}

require_once dirname(__FILE__).'/include/page_footer.php';
