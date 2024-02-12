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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


#include "async_http.h"
#include "zbxcommon.h"
#include "zbxip.h"
#include "zbx_discoverer_constants.h"
#include "zbxhttp.h"

#ifdef HAVE_LIBCURL

void	zbx_discovery_async_http_context_destroy(zbx_discovery_async_http_context_t *http_ctx)
{
	curl_easy_cleanup(http_ctx->easyhandle);
	zbx_free(http_ctx->reverse_dns);
	zbx_free(http_ctx);
}

int	zbx_discovery_async_check_http(CURLM *curl_mhandle, const char *config_source_ip, int timeout, const char *ip,
		unsigned short port, unsigned char type, zbx_discovery_async_http_context_t *http_ctx, char **error)
{
	CURLcode	err;
	CURLMcode	merr;
	char		url[MAX_STRING_LEN];
	CURLoption	opt;

	zbx_snprintf(url, sizeof(url), SUCCEED == zbx_is_ip6(ip) ? "%s[%s]" : "%s%s",
			SVC_HTTPS == type ? "https://" : "http://", ip);

	if (NULL == (http_ctx->easyhandle = curl_easy_init()))
	{
		*error = zbx_strdup(*error, "Cannot initialize cURL library");
		goto fail;
	}

	if (CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_USERAGENT,
			"Zabbix " ZABBIX_VERSION)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_URL, url)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_PORT, (long)port)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_NOBODY, 1L)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_SSL_VERIFYPEER, 0L)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_SSL_VERIFYHOST, 0L)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_INTERFACE,
			config_source_ip)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = CURLOPT_TIMEOUT,
			(long)timeout)) ||
			CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, opt = ZBX_CURLOPT_ACCEPT_ENCODING,
			"")))
	{
		*error = zbx_dsprintf(*error, "Cannot set cURL option [%d]: %s", (int)opt, curl_easy_strerror(err));
		goto fail;
	}

#if LIBCURL_VERSION_NUM >= 0x071304
	/* CURLOPT_PROTOCOLS is supported starting with version 7.19.4 (0x071304) */
	/* CURLOPT_PROTOCOLS was deprecated in favor of CURLOPT_PROTOCOLS_STR starting with version 7.85.0 (0x075500) */
#	if LIBCURL_VERSION_NUM >= 0x075500
	if (CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, CURLOPT_PROTOCOLS_STR, "HTTP,HTTPS")))
#	else
	if (CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, CURLOPT_PROTOCOLS,
			CURLPROTO_HTTP | CURLPROTO_HTTPS)))
#	endif
	{
		*error = zbx_dsprintf(*error, "Cannot set allowed protocols: %s", curl_easy_strerror(err));
		goto fail;
	}
#endif

	if (CURLE_OK != (err = curl_easy_setopt(http_ctx->easyhandle, CURLOPT_PRIVATE, http_ctx)))
	{
		*error = zbx_dsprintf(*error, "Cannot set pointer to private data: %s", curl_easy_strerror(err));
		goto fail;
	}

	if (CURLM_OK != (merr = curl_multi_add_handle(curl_mhandle, http_ctx->easyhandle)))
	{
		*error = zbx_dsprintf(*error, "Cannot add a standard curl handle to the multi stack: %s",
				curl_multi_strerror(merr));
		goto fail;
	}

	return SUCCEED;
fail:
	return FAIL;
}
#endif
