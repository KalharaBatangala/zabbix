/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
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

#include "proxy_group.h"
#include "dbconfig.h"
#include "dbsync.h"

ZBX_PTR_VECTOR_IMPL(pg_proxy_ptr, zbx_pg_proxy_t *)
ZBX_PTR_VECTOR_IMPL(pg_group_ptr, zbx_pg_group_t *)
ZBX_PTR_VECTOR_IMPL(pg_host_ptr, zbx_pg_host_t *)
ZBX_VECTOR_IMPL(pg_host, zbx_pg_host_t)

/******************************************************************************
 *                                                                            *
 * Purpose: sync proxy groups with configuration cache                        *
 *                                                                            *
 * Parameters: sync     - [IN] db synchronization data                        *
 *             revision - [IN] current sync revision                          *
 *                                                                            *
 * Comments: The result contains the following fields:                        *
 *           0 - proxy_groupid                                                *
 *           1 - failover_delay                                               *
 *           2 - min_online                                                   *
 *                                                                            *
 ******************************************************************************/
void	dc_sync_proxy_group(zbx_dbsync_t *sync, zbx_uint64_t revision)
{
	char			**row;
	zbx_uint64_t		rowid;
	unsigned char		tag;
	zbx_dc_proxy_group_t	*pg;
	int			ret;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	while (SUCCEED == (ret = zbx_dbsync_next(sync, &rowid, &row, &tag)))
	{
		/* removed rows will be always added at the end */
		if (ZBX_DBSYNC_ROW_REMOVE == tag)
			break;

		zbx_uint64_t	proxy_groupid;
		int		found;

		ZBX_STR2UINT64(proxy_groupid, row[0]);

		pg = (zbx_dc_proxy_group_t *)DCfind_id(&config->proxy_groups, proxy_groupid, sizeof(ZBX_DC_PROXY),
				&found);

		if (FAIL == zbx_is_time_suffix(row[1], &pg->failover_delay, ZBX_LENGTH_UNLIMITED))
		{
			zabbix_log(LOG_LEVEL_WARNING, "invalid proxy group '" ZBX_FS_UI64 "' failover delay '%s', "
					"using 60 seconds default value", pg->proxy_groupid, row[1]);
			pg->failover_delay = SEC_PER_MIN;
		}

		pg->min_online = atoi(row[2]);
		dc_strpool_replace(found, &pg->name, row[3]);

		pg->revision = revision;
	}

	for (; SUCCEED == ret; ret = zbx_dbsync_next(sync, &rowid, &row, &tag))
	{
		if (NULL == (pg = (zbx_dc_proxy_group_t *)zbx_hashset_search(&config->proxy_groups, &rowid)))
			continue;

		dc_strpool_release(pg->name);
		zbx_hashset_remove_direct(&config->proxy_groups, pg);
	}

	if (0 != sync->add_num + sync->update_num + sync->remove_num)
		config->revision.proxy_group = revision;

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

/******************************************************************************
 *                                                                            *
 * Purpose: update local proxy group cache                                    *
 *                                                                            *
 * Parameter: groups   - [IN/OUT] local proxy group cache                     *
 *            revision - [IN/OUT] local proxy group cache revision            *
 *                                                                            *
 * Return value: SUCCEED - local cache was updated                            *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
int	zbx_dc_fetch_proxy_groups(zbx_hashset_t *groups, zbx_uint64_t *revision)
{
	int		ret = FAIL;
	zbx_uint64_t	old_revision = *revision;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	if (*revision >= config->revision.proxy_group)
		goto out;

	zbx_hashset_iter_t	iter;
	zbx_dc_proxy_group_t	*dc_group;
	zbx_pg_group_t		*group;

	zbx_hashset_iter_reset(groups, &iter);
	while (NULL != (group = (zbx_pg_group_t *)zbx_hashset_iter_next(&iter)))
		group->flags = ZBX_PG_GROUP_FLAGS_NONE;

	RDLOCK_CACHE;

	*revision = config->revision.proxy_group;

	zbx_hashset_iter_reset(&config->proxy_groups, &iter);
	while (NULL != (dc_group = (zbx_dc_proxy_group_t *)zbx_hashset_iter_next(&iter)))
	{
		if (NULL == (group = (zbx_pg_group_t *)zbx_hashset_search(groups, &dc_group->proxy_groupid)))
		{
			zbx_pg_group_t	group_local = {.proxy_groupid = dc_group->proxy_groupid};

			group = (zbx_pg_group_t *)zbx_hashset_insert(groups, &group_local, sizeof(group_local));
			zbx_vector_pg_proxy_ptr_create(&group->proxies);
			zbx_vector_uint64_create(&group->hostids);
			zbx_vector_uint64_create(&group->new_hostids);
			group->flags = ZBX_PG_GROUP_SYNC_ADDED;
		}
		else
			group->flags = ZBX_PG_GROUP_SYNC_MODIFIED;

		group->sync_revision = *revision;

		if (dc_group->revision > group->revision)
		{
			group->revision = dc_group->revision;
			group->failover_delay = dc_group->failover_delay;
			group->min_online = dc_group->min_online;

			if (NULL == group->name || 0 != strcmp(group->name, dc_group->name))
				group->name = zbx_strdup(group->name, dc_group->name);
		}
	}

	UNLOCK_CACHE;

	ret = SUCCEED;
out:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s revision:" ZBX_FS_UI64 "->" ZBX_FS_UI64, __func__,
			zbx_result_string(ret), old_revision, *revision);
	return ret;
}

/******************************************************************************
 *                                                                            *
 * Purpose: update local proxy cache                                          *
 *                                                                            *
 * Parameter: proxies  - [IN/OUT] local proxy cache                           *
 *            revision - [IN/OUT] local proxy cache revision                  *
 *                                                                            *
 * Return value: SUCCEED - local cache was updated                            *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
int	zbx_dc_fetch_proxies(zbx_hashset_t *proxies, zbx_uint64_t *revision, zbx_vector_objmove_t *proxy_reloc)
{
	int		ret = FAIL;
	zbx_uint64_t	old_revision = *revision;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	if (*revision >= config->revision.proxy)
		goto out;

	zbx_hashset_iter_t	iter;
	ZBX_DC_PROXY		*dc_proxy;
	zbx_pg_proxy_t		*proxy;

	RDLOCK_CACHE;

	*revision = config->revision.proxy;

	zbx_hashset_iter_reset(&config->proxies, &iter);

	while (NULL != (dc_proxy = (ZBX_DC_PROXY *)zbx_hashset_iter_next(&iter)))
	{
		zbx_uint64_t	old_proxy_groupid;

		proxy = (zbx_pg_proxy_t *)zbx_hashset_search(proxies, &dc_proxy->proxyid);

		if (0 == dc_proxy->proxy_groupid)
		{
			if (NULL != proxy && NULL != proxy->group)
			{
				zbx_objmove_t	reloc = {
						.objid = proxy->proxyid,
						.srcid = proxy->group->proxy_groupid,
						.dstid = 0
					};

				zbx_vector_objmove_append_ptr(proxy_reloc, &reloc);
			}
			continue;
		}

		if (NULL == proxy)
		{
			zbx_pg_proxy_t	proxy_local = {.proxyid = dc_proxy->proxyid};

			proxy = (zbx_pg_proxy_t *)zbx_hashset_insert(proxies, &proxy_local, sizeof(proxy_local));

			zbx_vector_pg_host_ptr_create(&proxy->hosts);
			zbx_vector_pg_host_create(&proxy->deleted_group_hosts);
		}

		proxy->lastaccess = dc_proxy->lastaccess;

		old_proxy_groupid = (NULL == proxy->group ? 0 : proxy->group->proxy_groupid);

		if (old_proxy_groupid != dc_proxy->proxy_groupid)
		{
			zbx_objmove_t	reloc = {
					.objid = proxy->proxyid,
					.srcid = old_proxy_groupid,
					.dstid = dc_proxy->proxy_groupid
				};

			zbx_vector_objmove_append_ptr(proxy_reloc, &reloc);
		}

		if (NULL == proxy->name || 0 != strcmp(proxy->name, dc_proxy->name))
			proxy->name = zbx_strdup(proxy->name, dc_proxy->name);
	}

	UNLOCK_CACHE;

	ret = SUCCEED;
out:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s revision:" ZBX_FS_UI64 "->" ZBX_FS_UI64, __func__,
			zbx_result_string(ret), old_revision, *revision);
	return ret;
}

static void	dc_register_host_proxy(zbx_dc_host_proxy_t *hp)
{
	zbx_dc_host_proxy_index_t	*hpi, hpi_local = {.host = hp->host};

	if (NULL == (hpi = (zbx_dc_host_proxy_index_t *)zbx_hashset_search(&config->host_proxy_index, &hpi_local)))
	{
		hpi = (zbx_dc_host_proxy_index_t *)zbx_hashset_insert(&config->host_proxy_index, &hpi_local,
				sizeof(hpi_local));
		dc_strpool_acquire(hpi->host);
	}

	hpi->host_proxy = hp;
}

static void	dc_deregister_host_proxy(zbx_dc_host_proxy_t *hp)
{
	zbx_dc_host_proxy_index_t	*hpi, hpi_local = {.host = hp->host};

	if (NULL != (hpi = (zbx_dc_host_proxy_index_t *)zbx_hashset_search(&config->host_proxy_index, &hpi_local)))
	{
		dc_strpool_release(hpi->host);
		zbx_hashset_remove_direct(&config->host_proxy_index, hpi);
	}
}

void	dc_update_host_proxy(const char *host_old, const char *host_new)
{
	zbx_dc_host_proxy_index_t	*hpi, hpi_local = {.host = host_old};

	if (NULL != (hpi = (zbx_dc_host_proxy_index_t *)zbx_hashset_search(&config->host_proxy_index, &hpi_local)))
	{
		dc_strpool_release(hpi->host);
		zbx_hashset_remove_direct(&config->host_proxy_index, hpi);

		hpi_local.host_proxy = hpi->host_proxy;
		hpi_local.host = dc_strpool_intern(host_new);
		zbx_hashset_insert(&config->host_proxy_index, &hpi_local, sizeof(hpi_local));
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: sync host proxy links with configuration cache                    *
 *                                                                            *
 * Parameters: sync     - [IN] db synchronization data                        *
 *                                                                            *
 * Comments: The result contains the following fields:                        *
 *           0 - hostproxyid                                                  *
 *           1 - hostid                                                       *
 *           2 - host                                                         *
 *           3 - proxyid                                                      *
 *           4 - revision                                                     *
 *           5 - host.host (NULL on proxies)                                  *
 *                                                                            *
 ******************************************************************************/
void	dc_sync_host_proxy(zbx_dbsync_t *sync, zbx_uint64_t revision)
{
	char				**row;
	zbx_uint64_t			rowid;
	unsigned char			tag;
	zbx_dc_host_proxy_t		*hp;
	int				ret;
	ZBX_DC_HOST			*dc_host;
	zbx_vector_dc_host_ptr_t	hosts;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	zbx_vector_dc_host_ptr_create(&hosts);

	while (SUCCEED == (ret = zbx_dbsync_next(sync, &rowid, &row, &tag)))
	{
		/* removed rows will be always added at the end */
		if (ZBX_DBSYNC_ROW_REMOVE == tag)
			break;

		zbx_uint64_t	hostproxyid;
		int		found;

		ZBX_STR2UINT64(hostproxyid, row[0]);

		hp = (zbx_dc_host_proxy_t *)DCfind_id(&config->host_proxy, hostproxyid, sizeof(ZBX_DC_PROXY),
				&found);

		ZBX_DBROW2UINT64(hp->hostid, row[1]);
		ZBX_STR2UINT64(hp->proxyid, row[3]);
		ZBX_STR2UINT64(hp->revision, row[4]);

		if (SUCCEED != zbx_db_is_null(row[5]))	/* server */
		{
			dc_strpool_replace(found, &hp->host, row[5]);

			if (NULL != (dc_host = (ZBX_DC_HOST *)zbx_hashset_search(&config->hosts, &hp->hostid)))
			{
				if (0 != dc_host->proxy_groupid)
				{
					if (0 != dc_host->proxyid)
						dc_host_deregister_proxy(dc_host, dc_host->proxyid, revision);

					dc_host_register_proxy(dc_host, hp->proxyid, revision);
					dc_host->proxyid = hp->proxyid;
					dc_host->revision = revision;
					zbx_vector_dc_host_ptr_append(&hosts, dc_host);
				}
			}
		}
		else	/* proxy */
			dc_strpool_replace(found, &hp->host, row[2]);

		dc_register_host_proxy(hp);
	}

	for (; SUCCEED == ret; ret = zbx_dbsync_next(sync, &rowid, &row, &tag))
	{
		if (NULL == (hp = (zbx_dc_host_proxy_t *)zbx_hashset_search(&config->proxy_groups, &rowid)))
			continue;

		if (NULL != (dc_host = (ZBX_DC_HOST *)zbx_hashset_search(&config->hosts, &hp->hostid)))
		{
			if (0 != dc_host->proxy_groupid)
			{
				dc_host_deregister_proxy(dc_host, hp->proxyid, revision);
				dc_host->proxyid = 0;
				dc_host->revision = revision;
				zbx_vector_dc_host_ptr_append(&hosts, dc_host);
			}
		}

		dc_deregister_host_proxy(hp);
		zbx_hashset_remove_direct(&config->host_proxy, hp);
	}

	if (0 != hosts.values_num)
	{
		zbx_vector_uint64_t	hostids;

		zbx_vector_uint64_create(&hostids);

		for (int i = 0; i < hosts.values_num; i++)
		{
			ZBX_DC_INTERFACE	*interface;

			for (int j = 0; j < hosts.values[i]->interfaces_v.values_num; j++)
			{
				interface = (ZBX_DC_INTERFACE *)hosts.values[i]->interfaces_v.values[j];
				interface->reset_availability = 1;
			}
		}

		zbx_dbsync_process_active_avail_diff(&hostids);

		zbx_vector_uint64_destroy(&hostids);
	}

	zbx_vector_dc_host_ptr_destroy(&hosts);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

/******************************************************************************
 *                                                                            *
 * Purpose: get redirection information for the host                          *
 *                                                                            *
 * Parameters: host     - [IN] host name                                      *
 *             redirect - [OUT] redirection information                       *
 *                                                                            *
 * Return value: SUCCEED - host must be redirected to the returned address    *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
int	dc_get_host_redirect(const char *host, zbx_comms_redirect_t *redirect)
{
	zbx_dc_host_proxy_index_t	*hpi, hpi_local = {.host = host};
	ZBX_DC_PROXY			*proxy;

	if (NULL == (hpi = (zbx_dc_host_proxy_index_t *)zbx_hashset_search(&config->host_proxy_index, &hpi_local)))
		return FAIL;

	if (NULL == (proxy = (ZBX_DC_PROXY *)zbx_hashset_search(&config->proxies, &hpi->host_proxy->proxyid)))
		return FAIL;

	if (NULL != config->proxy_hostname)
	{
		if (0 == strcmp(proxy->name, config->proxy_hostname))
		{
			int	now;

			now = (int)time(NULL);

			if (now - config->proxy_lastonline < config->proxy_failover_delay ||
					now - hpi->lastreset < config->proxy_failover_delay)
			{
				return FAIL;
			}

			hpi->lastreset = now;
			redirect->reset = ZBX_REDIRECT_RESET;

			return SUCCEED;
		}
	}

	const char	*local_port = proxy->local_port;

	if ('\0' != *local_port && '{' == *local_port)
	{
		um_cache_resolve_const(config->um_cache, NULL, 0, proxy->local_port, ZBX_MACRO_ENV_NONSECURE,
				&local_port);
	}

	if ('\0' != *local_port)
		zbx_snprintf(redirect->address, sizeof(redirect->address), "%s:%s", proxy->local_address, local_port);
	else
		zbx_strlcpy(redirect->address, proxy->local_address, sizeof(redirect->address));

	redirect->revision = hpi->host_proxy->revision;
	redirect->reset = 0;

	return SUCCEED;
}

/******************************************************************************
 *                                                                            *
 * Purpose: set proxy failover delay in configuration cache                   *
 *                                                                            *
 ******************************************************************************/
void	zbx_dc_set_proxy_failover_delay(int failover_delay)
{
	/* failover delay can be updated only by one process at time, */
	/* so it can be checked without locking before update        */
	if (config->proxy_failover_delay != failover_delay)
	{
		WRLOCK_CACHE;
		config->proxy_failover_delay = failover_delay;
		UNLOCK_CACHE;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: set proxy last online timestmap in configuration cache            *
 *                                                                            *
 ******************************************************************************/
void	zbx_dc_set_proxy_lastonline(int lastonline)
{
	WRLOCK_CACHE;
	config->proxy_lastonline = lastonline;
	UNLOCK_CACHE;
}

/******************************************************************************
 *                                                                            *
 * Purpose: get proxy group revision                                          *
 *                                                                            *
 ******************************************************************************/
zbx_uint64_t	zbx_dc_get_proxy_group_revision(zbx_uint64_t proxy_groupid)
{
	zbx_uint64_t	revision;

	RDLOCK_CACHE;
	zbx_dc_proxy_group_t	*pg;

	if (NULL != (pg = (zbx_dc_proxy_group_t *)zbx_hashset_search(&config->proxy_groups, &proxy_groupid)))
		revision = pg->revision;
	else
		revision = 0;

	UNLOCK_CACHE;

	return revision;
}
