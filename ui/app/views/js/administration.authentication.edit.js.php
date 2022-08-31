<?php
/*
** Zabbix
** Copyright (C) 2001-2022 Zabbix SIA
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


/**
 * @var CView $this
 */
?>

<script>
	const view = new class {

		constructor() {
			this.form = null;
			this.db_authentication_type = null;
			this.allow_jit = null;
		}

		init({ldap_servers, ldap_default_row_index, db_authentication_type, saml_provision_groups, saml_provision_media}) {
			this.form = document.getElementById('authentication-form');
			this.db_authentication_type = db_authentication_type;
			this.allow_jit = document.getElementById('saml_allow_jit');

			this._addEventListeners();
			this._addLdapServers(ldap_servers, ldap_default_row_index);
			this._renderProvisionGroups(saml_provision_groups);
			this._renderProvisionMedia(saml_provision_media);

			this.toggleScimProvisioning(this.allow_jit.checked);
			this._initSortable(document.getElementById('saml-group-table'));
		}

		_addEventListeners() {
			document
				.getElementById('ldap-servers')
				.addEventListener('click', (e) => {
					if (e.target.classList.contains('js-add')) {
						this.editLdapServer();
					}
					else if (e.target.classList.contains('js-edit')) {
						this.editLdapServer(e.target.closest('tr'));
					}
					else if (e.target.classList.contains('js-remove')) {
						const table = e.target.closest('table');
						const userdirectoryid_input = e.target.closest('tr')
							.querySelector('input[name$="[userdirectoryid]"]');

						if (userdirectoryid_input !== null) {
							const input = document.createElement('input');
							input.type = 'hidden';
							input.name = 'ldap_removed_userdirectoryids[]';
							input.value = userdirectoryid_input.value;
							this.form.appendChild(input);
						}

						e.target.closest('tr').remove();

						if (table.querySelector('input[name="ldap_default_row_index"]:checked') === null) {
							const default_ldap = table.querySelector('input[name="ldap_default_row_index"]');

							if (default_ldap !== null) {
								default_ldap.checked = true;
							}
						}
					}
				});

			document.getElementById('http_auth_enabled').addEventListener('change', (e) => {
				this.form.querySelectorAll('[name^=http_]').forEach(field => {
					if (!field.isSameNode(e.target)) {
						field.disabled = !e.target.checked;
					}
				});
			});

			if (document.getElementById('saml_auth_enabled') !== null) {
				document.getElementById('saml_auth_enabled').addEventListener('change', (e) => {
					this.form.querySelectorAll('.saml-enabled').forEach(field => {
						if (!field.isSameNode(e.target)) {
							field.disabled = !e.target.checked;
						}
					});
				});
			}

			this.allow_jit.addEventListener('change', (e) => {
				this.toggleScimProvisioning(e.target.checked);
			});

			document
				.getElementById('saml-group-table')
				.addEventListener('click', (e) => {
					if (e.target.classList.contains('js-add')) {
						this.editSamlProvisionGroup();
					}
					else if (e.target.classList.contains('js-edit')) {
						this.editSamlProvisionGroup(e.target.closest('tr'));
					}
					else if (e.target.classList.contains('js-remove')) {
						e.target.closest('tr').remove()
					}
					else if (e.target.classList.contains('js-enabled')) {
						this.toggleFallbackStatus('off', e.target.closest('td'));
					}
					else if (e.target.classList.contains('js-disabled')) {
						this.toggleFallbackStatus('on', e.target.closest('td'));
					}
				});

			document
				.getElementById('saml-media-type-mapping-table')
				.addEventListener('click', (e) => {
					if (e.target.classList.contains('js-add')) {
						this.editSamlProvisionMedia();
					}
					else if (e.target.classList.contains('js-edit')) {
						this.editSamlProvisionMedia(e.target.closest('tr'));
					}
					else if (e.target.classList.contains('js-remove')) {
						e.target.closest('tr').remove()
					}
				});

			this.form.addEventListener('submit', (e) => {
				if (!this._authFormSubmit()) {
					e.preventDefault();
				}
			});
		}

		toggleFallbackStatus(action, target) {
			const new_action = document.createElement('td');
			if (action === 'on') {
				new_action.innerHTML = '<button type="button" class="<?= ZBX_STYLE_BTN_LINK . ' ' . ZBX_STYLE_GREEN?> js-enabled"><?= _('Enabled') ?></button>';
				new_action.innerHTML += '<input type="hidden" name="saml_groups[#{row_index}][fallback_status]" value="1">';
			}
			else if (action === 'off') {
				new_action.innerHTML = '<button type="button" class="<?= ZBX_STYLE_BTN_LINK . ' ' . ZBX_STYLE_RED?> js-disabled"><?= _('Disabled') ?></button>';
				new_action.innerHTML += '<input type="hidden" name="saml_groups[#{row_index}][fallback_status]" value="0">';
			}
			target.replaceWith(new_action);
		}

		_initSortable(element) {
			// const is_disabled = element.querySelectorAll('tr.sortable').length < 2;

			$(element).sortable({
				// disabled: is_disabled,
				items: 'tbody tr.sortable',
				cancel: '[data-row_fallback="<?= GROUP_MAPPING_FALLBACK ?>"]',
				axis: 'y',
				containment: 'parent',
				cursor: 'grabbing',
				handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>',
				tolerance: 'pointer',
				opacity: 0.6,
				helper: function(e, ui) {
					for (let td of ui.find('>td')) {
						let $td = $(td);
						$td.attr('width', $td.width())
					}

					// when dragging element on safari, it jumps out of the table
					if (SF) {
						// move back draggable element to proper position
						ui.css('left', (ui.offset().left - 2) + 'px');
					}

					return ui;
				},
				stop: function(e, ui) {
					if ($(ui.item).prev('[data-row_fallback="<?= GROUP_MAPPING_FALLBACK ?>"]').length) {
						return false;
					}
					ui.item.find('>td').removeAttr('width');
					ui.item.removeAttr('style');
				},
				start: function(e, ui) {
					$(ui.placeholder).height($(ui.helper).height());
				}
			});
		}

		_authFormSubmit() {
			const fields_to_trim = ['#saml_idp_entityid', '#saml_sso_url', '#saml_slo_url', '#saml_username_attribute',
				'#saml_sp_entityid', '#saml_nameid_format'
			];
			document.querySelectorAll(fields_to_trim.join(', ')).forEach((elem) => {
				elem.value = elem.value.trim();
			});

			const auth_type = document.querySelector('[name=authentication_type]:checked').value;
			const warning_msg = <?= json_encode(
				_('Switching authentication method will reset all except this session! Continue?')
			) ?>;

			return (auth_type == this.db_authentication_type || confirm(warning_msg));
		}

		_addLdapServers(ldap_servers, ldap_default_row_index) {
			for (const [row_index, ldap] of Object.entries(ldap_servers)) {
				ldap.row_index = row_index;
				ldap.is_default = (ldap.row_index == ldap_default_row_index) ? 'checked' : '';

				document
						.querySelector('#ldap-servers tbody')
						.appendChild(this._prepareServerRow(ldap));
			}
		}

		_renderProvisionGroups(saml_provision_groups) {
			for (const [row_index, saml_provision_group] of Object.entries(saml_provision_groups)) {
				saml_provision_group.row_index = row_index;

				document
					.querySelector('#saml-group-table tbody')
					.appendChild(this._renderProvisionGroupRow(saml_provision_group));
			}
		}

		_renderProvisionMedia(saml_provision_media) {
			for (const [row_index, saml_media] of Object.entries(saml_provision_media)) {
				saml_media.row_index = row_index;

				document
					.querySelector('#saml-media-type-mapping-table tbody')
					.appendChild(this._renderProvisionMediaRow(saml_media));
			}
		}

		editSamlProvisionGroup(row = null) {
			let popup_params;
			let row_index;

			if (row != null) {
				row_index = row.dataset.row_index;
				const user_groups = row.querySelectorAll(`[name="saml_provision_groups[${row_index}][user_groups][]"`)

				popup_params = {
					name: row.querySelector(`[name="saml_provision_groups[${row_index}][name]"`).value,
					usrgrpid: [...user_groups].map(usrgrp => usrgrp.value),
					roleid: row.querySelector(`[name="saml_provision_groups[${row_index}][roleid]"`).value,
					is_fallback: row.querySelector(`[name="saml_provision_groups[${row_index}][is_fallback]"`).value
				};
			}
			else {
				const rows = document.querySelectorAll('#saml-group-table [data-row_index]');
				row_index = Math.max([...rows].map(row => row.dataset.row_index)) + 1;

				popup_params = {
					add_group: 1,
					is_fallback: <?= GROUP_MAPPING_REGULAR ?>
				};
			}

			popup_params.name_label = t('SAML group pattern'); // TODO: this will be deleted, need to discuss with Miks how to perform it correctly.

			const overlay = PopUp('popup.usergroupmapping.edit', popup_params, {dialogueid: 'user_group_edit'});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				const saml_provision_group = {...e.detail, ...{row_index: row_index}};

				if (row === null) {
					const fallback_row = document.querySelector('[data-row_fallback="<?= GROUP_MAPPING_FALLBACK ?>"]');
					if (fallback_row !== null) {
						fallback_row.parentNode.insertBefore(
							this._renderProvisionGroupRow(saml_provision_group), fallback_row
						);
					}
					else {
						document
							.querySelector('#saml-group-table tbody')
							.appendChild(this._renderProvisionGroupRow(saml_provision_group));
					}
				}
				else {
					row.parentNode.insertBefore(this._renderProvisionGroupRow(saml_provision_group), row);
					row.remove();
				}
			});
		}

		editSamlProvisionMedia(row = null) {
			let popup_params;
			let row_index;

			if (row != null) {
				row_index = row.dataset.row_index;

				popup_params = {
					name: row.querySelector(`[name="saml_provision_media[${row_index}][name]"`).value,
					attribute: row.querySelector(`[name="saml_provision_media[${row_index}][attribute]"`).value,
					mediatypeid: row.querySelector(`[name="saml_provision_media[${row_index}][mediatypeid]"`).value
				};
			}
			else {
				const rows = document.querySelectorAll('#saml-media-type-mapping-table [data-row_index]');
				row_index = Math.max([...rows].map(row => row.dataset.row_index)) + 1;

				popup_params = {
					add_media_type_mapping: 1
				};
			}

			const overlay = PopUp('popup.mediatypemapping.edit', popup_params, {dialogueid: 'media_type_mapping_edit'});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				const saml_media_type_mapping = {...e.detail, ...{row_index: row_index}};
console.log(saml_media_type_mapping);
				if (row === null) {
					document
						.querySelector('#saml-media-type-mapping-table tbody')
						.appendChild(this._renderProvisionMediaRow(saml_media_type_mapping));
				}
				else {
					row.replaceWith(this._renderProvisionMediaRow(saml_media_type_mapping));
				}
			});
		}

		editLdapServer(row = null) {
			let popup_params;

			if (row !== null) {
				const row_index = row.dataset.row_index;

				popup_params = {
					row_index,
					add_ldap_server: 0,
					name: row.querySelector(`[name="ldap_servers[${row_index}][name]"`).value,
					host: row.querySelector(`[name="ldap_servers[${row_index}][host]"`).value,
					port: row.querySelector(`[name="ldap_servers[${row_index}][port]"`).value,
					base_dn: row.querySelector(`[name="ldap_servers[${row_index}][base_dn]"`).value,
					search_attribute: row.querySelector(`[name="ldap_servers[${row_index}][search_attribute]"`).value,
					search_filter: row.querySelector(`[name="ldap_servers[${row_index}][search_filter]"`).value,
					start_tls: row.querySelector(`[name="ldap_servers[${row_index}][start_tls]"`).value,
					bind_dn: row.querySelector(`[name="ldap_servers[${row_index}][bind_dn]"`).value,
					description: row.querySelector(`[name="ldap_servers[${row_index}][description]"`).value
				};

				const userdirectoryid_input = row.querySelector(`[name="ldap_servers[${row_index}][userdirectoryid]"`);
				const bind_password_input = row.querySelector(`[name="ldap_servers[${row_index}][bind_password]"`);

				if (userdirectoryid_input !== null) {
					popup_params['userdirectoryid'] = userdirectoryid_input.value;
				}

				if (bind_password_input !== null) {
					popup_params['bind_password'] = bind_password_input.value;
				}
			}
			else {
				let row_index = 0;

				while (document.querySelector(`#ldap-servers [data-row_index="${row_index}"]`) !== null) {
					row_index++;
				}

				popup_params = {
					row_index,
					add_ldap_server: 1
				};
			}

			const overlay = PopUp('popup.ldap.edit', popup_params, {dialogueid: 'ldap_edit'});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				const ldap = e.detail;

				if (row === null) {
					ldap.is_default = document.getElementById('ldap-servers')
							.querySelector('input[name="ldap_default_row_index"]:checked') === null
						? 'checked'
						: '';
					ldap.usrgrps = 0;

					document
						.querySelector('#ldap-servers tbody')
						.appendChild(this._prepareServerRow(ldap));
				}
				else {
					ldap.is_default = row.querySelector('input[name="ldap_default_row_index"]').checked === true
						? 'checked'
						: '';
					ldap.usrgrps = row.querySelector('.js-ldap-usergroups').textContent;

					row.parentNode.insertBefore(this._prepareServerRow(ldap), row);
					row.remove();
				}
			});
		}

		_prepareServerRow(ldap) {
			const template_ldap_server_row = new Template(this._templateLdapServerRow());
			const template = document.createElement('template');
			template.innerHTML = template_ldap_server_row.evaluate(ldap).trim();
			const row = template.content.firstChild;

			const optional_fields = ['userdirectoryid', 'bind_password', 'start_tls', 'search_filter'];

			for (const field of optional_fields) {
				if (!(field in ldap)) {
					row.querySelector('input[name="ldap_servers[' + ldap.row_index + '][' + field + ']"]').remove();
				}
			}

			if (ldap.usrgrps > 0) {
				row.querySelector('.js-remove').disabled = true;
			}

			return row;
		}

		_renderProvisionGroupRow(saml_provision_group) {
			const template_saml_group_row = new Template(this._templateProvisionGroupRow());
			let template = document.createElement('template');
			saml_provision_group.user_group_names = Object.values(saml_provision_group.user_groups)
				.map(user_group => user_group.name).join(', ');

			if (saml_provision_group.is_fallback == <?= GROUP_MAPPING_FALLBACK ?>) {
				saml_provision_group.drag_icon_class = '<?= ZBX_STYLE_DISABLED ?>';
				if (saml_provision_group.fallback_status == <?= GROUP_MAPPING_FALLBACK_ON ?>) {
					saml_provision_group.action_label = '<?= _('Enabled') ?>';
					saml_provision_group.action_class = 'js-enabled <?= ZBX_STYLE_GREEN ?>';
				}
				else {
					saml_provision_group.action_label = '<?= _('Disabled') ?>';
					saml_provision_group.action_class = 'js-disabled <?= ZBX_STYLE_RED ?>';
				}
			}
			else {
				saml_provision_group.action_label = '<?= _('Remove') ?>';
				saml_provision_group.action_class = 'js-remove';
			}

			template.innerHTML = template_saml_group_row.evaluate(saml_provision_group).trim();
			template = template.content.firstChild;

			for (const user_group of Object.values(saml_provision_group.user_groups)) {
				let usergroupid_element = document.createElement('template');
				let usergroupid_template = new Template(
					`<input type="hidden" name="saml_provision_groups[${saml_provision_group.row_index}][user_groups][]" value="${user_group.usrgrpid}">`
				);
				usergroupid_element.innerHTML = usergroupid_template.evaluate(user_group).trim();

				let parent_element = template.querySelector('a').parentNode;
				parent_element.appendChild(usergroupid_element.content.firstChild);
			}

			return template;
		}

		_renderProvisionMediaRow(saml_media) {
			const template_saml_media_mapping_row = new Template(this._templateProvisionMediaRow());
			const template = document.createElement('template');

			template.innerHTML = template_saml_media_mapping_row.evaluate(saml_media).trim();

			return template.content.firstChild;
		}

		_templateLdapServerRow() {
			return `
				<tr data-row_index="#{row_index}">
					<td>
						<a href="javascript:void(0);" class="wordwrap js-edit">#{name}</a>
						<input type="hidden" name="ldap_servers[#{row_index}][userdirectoryid]" value="#{userdirectoryid}">
						<input type="hidden" name="ldap_servers[#{row_index}][name]" value="#{name}">
						<input type="hidden" name="ldap_servers[#{row_index}][host]" value="#{host}">
						<input type="hidden" name="ldap_servers[#{row_index}][port]" value="#{port}">
						<input type="hidden" name="ldap_servers[#{row_index}][base_dn]" value="#{base_dn}">
						<input type="hidden" name="ldap_servers[#{row_index}][search_attribute]" value="#{search_attribute}">
						<input type="hidden" name="ldap_servers[#{row_index}][search_filter]" value="#{search_filter}">
						<input type="hidden" name="ldap_servers[#{row_index}][start_tls]" value="#{start_tls}">
						<input type="hidden" name="ldap_servers[#{row_index}][bind_dn]" value="#{bind_dn}">
						<input type="hidden" name="ldap_servers[#{row_index}][bind_password]" value="#{bind_password}">
						<input type="hidden" name="ldap_servers[#{row_index}][description]" value="#{description}">
					</td>
					<td class="wordbreak">#{host}</td>
					<td class="js-ldap-usergroups">#{usrgrps}</td>
					<td>
						<input type="radio" name="ldap_default_row_index" value="#{row_index}" #{is_default}>
					</td>
					<td>
						<button type="button" class="<?= ZBX_STYLE_BTN_LINK ?> js-remove"><?= _('Remove') ?></button>
					</td>
				</tr>
			`;
		}

		_templateProvisionGroupRow() {
			return `
				<tr data-row_index="#{row_index}" data-row_fallback="#{is_fallback}" class="sortable">
					<td class="td-drag-icon">
						<div class="drag-icon ui-sortable-handle #{drag_icon_class}"></div>
					</td>
					<td>
						<a href="javascript:void(0);" class="wordwrap js-edit">#{name}</a>
						<input type="hidden" name="saml_provision_groups[#{row_index}][name]" value="#{name}">
						<input type="hidden" name="saml_provision_groups[#{row_index}][roleid]" value="#{roleid}">
						<input type="hidden" name="saml_provision_groups[#{row_index}][is_fallback]" value="#{is_fallback}">
						<input type="hidden" name="saml_provision_groups[#{row_index}][fallback_status]" value="#{fallback_status}">
					</td>
					<td class="wordbreak">#{user_group_names}</td>
					<td class="wordbreak">#{role_name}</td>
					<td>
						<button type="button" class="<?= ZBX_STYLE_BTN_LINK ?> #{action_class}">#{action_label}</button>
					</td>
				</tr>
			`;
		}

		_templateProvisionMediaRow() {
			return `
				<tr data-row_index="#{row_index}">
					<td>
						<a href="javascript:void(0);" class="wordwrap js-edit">#{name}</a>
						<input type="hidden" name="saml_provision_media[#{row_index}][name]" value="#{name}">
						<input type="hidden" name="saml_provision_media[#{row_index}][mediatypeid]" value="#{mediatypeid}">
						<input type="hidden" name="saml_provision_media[#{row_index}][attribute]" value="#{attribute}">
					</td>
					<td class="wordbreak">#{mediatype_name}</td>
					<td class="wordbreak">#{attribute}</td>
					<td>
						<button type="button" class="<?= ZBX_STYLE_BTN_LINK ?> js-remove"><?= _('Remove') ?></button>
					</td>
				</tr>
			`;
		}

		toggleScimProvisioning(checked) {
			for (const element of this.form.querySelectorAll('.saml-allow-jit')) {
				element.classList.toggle('<?= ZBX_STYLE_DISPLAY_NONE ?>', !checked);
			}
		}
	};
</script>
