/* LXC v2 - container editor */
(function () {
  'use strict';

  function init() {

  var EDIT = window.LXCV2_EDIT || { name: '', state: '', nics: [], links: [], hostMemory: 0 };
  var AJAX = '/plugins/lxc/include/ajax.php';

  /* ------------------------------------------------------------------ utils */

  function toast(message, type) {
    var el = document.getElementById('lxcv2-toast');
    if (!el) {
      return;
    }
    el.textContent = message;
    el.className = 'lxcv2-toast show ' + (type || 'info');
    window.clearTimeout(el._timer);
    el._timer = window.setTimeout(function () {
      el.className = 'lxcv2-toast';
    }, 4000);
  }

  function post(action, data) {
    var headers = EDIT.csrfToken ? { 'X-CSRF-Token': EDIT.csrfToken } : {};

    return $.ajax({
      url: AJAX,
      method: 'POST',
      dataType: 'text',
      headers: headers,
      data: Object.assign({ lxc: '', action: action, container: EDIT.name }, data || {})
    }).then(
      function (text) { return { ok: true, text: String(text) }; },
      function (xhr) { return { ok: false, text: xhr.responseText || 'Request failed' }; }
    );
  }

  function parseResult(result) {
    var payload;
    try {
      payload = JSON.parse(result.text);
    } catch (e) {
      return { ok: false, message: result.text || 'Unexpected server response' };
    }

    return payload;
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* ------------------------------------------------------------------- tabs */

  function activateTab(name) {
    var tabs = document.querySelectorAll('.lxcv2-tab');
    var panels = document.querySelectorAll('.lxcv2-panel');
    Array.prototype.forEach.call(tabs, function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === name);
    });
    Array.prototype.forEach.call(panels, function (panel) {
      panel.classList.toggle('active', panel.id === 'tab-' + name);
    });
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, null, '#' + name);
    }
  }

  Array.prototype.forEach.call(document.querySelectorAll('.lxcv2-tab'), function (tab) {
    tab.addEventListener('click', function () {
      activateTab(tab.getAttribute('data-tab'));
    });
  });

  /* -------------------------------------------------------------- networking */

  var linkOptionsCache = null;

  function linkOptions(selected) {
    if (linkOptionsCache === null) {
      linkOptionsCache = EDIT.links.map(function (link) {
        var label = link.name + ' (' + link.kind + ')';
        if (link.addresses && link.addresses.length) {
          label += ' - ' + link.addresses[0];
        }
        return '<option value="' + escapeHtml(link.name) + '">' + escapeHtml(label) + '</option>';
      }).join('');
    }

    var known = EDIT.links.some(function (link) {
      return link.name === selected;
    });
    var extra = (!known && selected) ? '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + '</option>' : '';

    return extra + linkOptionsCache;
  }

  function nicCard(nic, index) {
    var html = '';
    html += '<div class="lxcv2-card" data-index="' + index + '">';
    html += '<div class="lxcv2-card-head"><span><i class="fa fa-network-wired"></i> Interface ' + index + '</span>';
    html += '<button type="button" class="lxcv2-icon-btn danger" title="Remove interface" onclick="removeNic(' + index + ')"><i class="fa fa-trash"></i></button></div>';

    html += '<div class="lxcv2-card-body">';

    html += '<dl><dt>Type</dt><dd><select data-field="type">';
    ['veth', 'macvlan'].forEach(function (type) {
      html += '<option value="' + type + '"' + (nic.type === type ? ' selected' : '') + '>' + type + (type === 'veth' ? ' (bridged)' : ' (host macvlan)') + '</option>';
    });
    html += '</select></dd></dl>';

    html += '<dl><dt>Link / bridge</dt><dd><select data-field="link">' + linkOptions(nic.link) + '</select></dd></dl>';
    html += '<dl><dt>Name in container</dt><dd><input data-field="name" value="' + escapeHtml(nic.name) + '" placeholder="eth0"></dd></dl>';
    html += '<dl><dt>MAC address</dt><dd><input data-field="hwaddr" value="' + escapeHtml(nic.hwaddr) + '" placeholder="52:54:00:00:00:00"></dd></dl>';
    html += '<dl><dt>VLAN id</dt><dd><input data-field="vlanId" type="number" min="1" max="4094" value="' + escapeHtml(nic.vlanId === null ? '' : nic.vlanId) + '" placeholder="untagged"></dd></dl>';

    html += '<dl><dt>IPv4</dt><dd class="lxcv2-inline">';
    html += '<select data-field="ipv4Mode">';
    [['dhcp', 'DHCP'], ['static', 'Static'], ['none', 'Disabled']].forEach(function (mode) {
      html += '<option value="' + mode[0] + '"' + (nic.ipv4Mode === mode[0] ? ' selected' : '') + '>' + mode[1] + '</option>';
    });
    html += '</select>';
    html += '<input data-field="ipv4Address" value="' + escapeHtml(nic.ipv4Address) + '" placeholder="192.168.1.50/24">';
    html += '<input data-field="ipv4Gateway" value="' + escapeHtml(nic.ipv4Gateway) + '" placeholder="gateway">';
    html += '</dd></dl>';

    html += '<dl><dt>IPv6</dt><dd class="lxcv2-inline">';
    html += '<select data-field="ipv6Mode">';
    [['none', 'Disabled'], ['auto', 'SLAAC / auto'], ['static', 'Static']].forEach(function (mode) {
      html += '<option value="' + mode[0] + '"' + (nic.ipv6Mode === mode[0] ? ' selected' : '') + '>' + mode[1] + '</option>';
    });
    html += '</select>';
    html += '<input data-field="ipv6Address" value="' + escapeHtml(nic.ipv6Address) + '" placeholder="fd00::50/64">';
    html += '<input data-field="ipv6Gateway" value="' + escapeHtml(nic.ipv6Gateway) + '" placeholder="gateway">';
    html += '</dd></dl>';

    html += '<dl><dt>DNS servers</dt><dd><input data-field="dns" value="' + escapeHtml((nic.dns || []).join(', ')) + '" placeholder="1.1.1.1, 8.8.8.8 (max 3)"></dd></dl>';
    html += '<p style="font-size:9pt;opacity:.8;">Custom DNS applies only with static IPv4. With DHCP, the container uses the DNS server advertised by DHCP.</p>';

    html += '<dl><dt>Advanced</dt><dd class="lxcv2-inline">';
    html += '<label class="lxcv2-check"><input type="checkbox" data-field="flagsDown"' + (nic.flagsDown ? ' checked' : '') + '> <span>link down</span></label>';
    html += '<label class="lxcv2-check"><input type="checkbox" data-field="vethRouter"' + (nic.vethMode === 'router' ? ' checked' : '') + '> <span>veth router mode</span></label>';
    html += '</dd></dl>';

    html += '</div></div>';

    return html;
  }

  function renderNics() {
    var container = document.getElementById('nic-list');
    if (!container) {
      return;
    }
    if (!EDIT.nics.length) {
      EDIT.nics.push({ type: 'veth', link: 'br0', name: 'eth0', hwaddr: '', vlanId: null, ipv4Mode: 'dhcp', ipv4Address: '', ipv4Gateway: '', ipv6Mode: 'none', ipv6Address: '', ipv6Gateway: '', dns: [] });
    }
    container.innerHTML = EDIT.nics.map(nicCard).join('');
  }

  function readNicsFromDom() {
    var cards = document.querySelectorAll('#nic-list .lxcv2-card');
    var result = [];

    Array.prototype.forEach.call(cards, function (card, index) {
      function field(name) {
        var el = card.querySelector('[data-field="' + name + '"]');
        return el ? el.value.trim() : '';
      }
      function checked(name) {
        var el = card.querySelector('[data-field="' + name + '"]');
        return el ? el.checked : false;
      }

      result.push({
        type: field('type'),
        link: field('link'),
        name: field('name'),
        hwaddr: field('hwaddr'),
        vlanId: field('vlanId'),
        ipv4Mode: field('ipv4Mode'),
        ipv4Address: field('ipv4Address'),
        ipv4Gateway: field('ipv4Gateway'),
        ipv6Mode: field('ipv6Mode'),
        ipv6Address: field('ipv6Address'),
        ipv6Gateway: field('ipv6Gateway'),
        dns: field('dns').split(/[\s,;]+/).filter(Boolean),
        flagsDown: checked('flagsDown'),
        vethMode: checked('vethRouter') ? 'router' : 'bridge'
      });
    });

    return result;
  }

  function validateNics(nics) {
    var ipv4 = /^([0-9]{1,3}(\.[0-9]{1,3}){3})(\/([0-9]{1,2}))?$/;
    var ipv6 = /^[0-9a-fA-F:]+(\/[0-9]{1,3})?$/;

    for (var i = 0; i < nics.length; i++) {
      var nic = nics[i];
      if (!/^[A-Za-z0-9_.-]{1,15}$/.test(nic.link)) {
        return 'Interface ' + i + ': invalid link "' + nic.link + '".';
      }
      if (!/^[A-Za-z0-9_.-]{1,15}$/.test(nic.name)) {
        return 'Interface ' + i + ': invalid name "' + nic.name + '".';
      }
      if (nic.hwaddr !== '' && !/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/.test(nic.hwaddr)) {
        return 'Interface ' + i + ': invalid MAC address.';
      }
      if (nic.vlanId !== '' && (Number(nic.vlanId) < 1 || Number(nic.vlanId) > 4094)) {
        return 'Interface ' + i + ': VLAN id must be between 1 and 4094.';
      }
      if (nic.ipv4Mode === 'static' && !ipv4.test(nic.ipv4Address)) {
        return 'Interface ' + i + ': invalid IPv4 address (expected 192.168.1.50/24).';
      }
      if (nic.ipv4Mode === 'static' && nic.ipv4Gateway !== '' && !/^([0-9]{1,3}(\.[0-9]{1,3}){3})$/.test(nic.ipv4Gateway)) {
        return 'Interface ' + i + ': invalid IPv4 gateway.';
      }
      if (nic.ipv6Mode === 'static' && !ipv6.test(nic.ipv6Address)) {
        return 'Interface ' + i + ': invalid IPv6 address (expected fd00::50/64).';
      }
      if (nic.dns && nic.dns.length > 3) {
        return 'Interface ' + i + ': at most 3 DNS servers are supported.';
      }
      if (nic.dns) {
        for (var d = 0; d < nic.dns.length; d++) {
          if (!/^([0-9]{1,3}(\.[0-9]{1,3}){3}|[0-9a-fA-F:]+)$/.test(nic.dns[d])) {
            return 'Interface ' + i + ': invalid DNS server "' + nic.dns[d] + '".';
          }
        }
      }
    }

    return null;
  }

  window.addNic = function () {
    var nics = readNicsFromDom();
    nics.push({
      type: 'veth',
      link: 'br0',
      name: 'eth' + nics.length,
      hwaddr: '',
      vlanId: null,
      ipv4Mode: 'dhcp',
      ipv4Address: '',
      ipv4Gateway: '',
      ipv6Mode: 'none',
      ipv6Address: '',
      ipv6Gateway: ''
    });
    EDIT.nics = nics;
    renderNics();
  };

  window.removeNic = function (index) {
    var nics = readNicsFromDom();
    if (nics.length <= 1) {
      toast('A container needs at least one interface.', 'error');
      return;
    }
    nics.splice(index, 1);
    EDIT.nics = nics;
    renderNics();
  };

  window.saveNetwork = function () {
    var nics = readNicsFromDom();
    var error = validateNics(nics);
    if (error) {
      toast(error, 'error');
      return;
    }

    post('saveNetwork', { nics: JSON.stringify(nics) }).then(function (result) {
      var payload = parseResult(result);
      if (payload.ok) {
        toast(payload.message || 'Network saved.', 'ok');
        EDIT.nics = nics;
        if (EDIT.state === 'RUNNING') {
          toast('Network saved. Restart the container to apply it.', 'info');
        }
      } else {
        toast(payload.message || 'Failed to save the network configuration.', 'error');
      }
    }).catch(function () {
      toast('Request failed.', 'error');
    });
  };

  /* --------------------------------------------------------------- quick ops */

  window.lxcQuickAction = function (action, name) {
    post(action, { container: name }).then(function () {
      window.location.reload();
    }).catch(function () {
      toast('Request failed.', 'error');
    });
  };

  window.startConsole = function (name) {
    if (typeof window.openTerminal === 'function') {
      window.openTerminal('lxc', name);
      return;
    }
    toast('Terminal is not available on this page, open it from the container list.', 'error');
  };

  /* ------------------------------------------------------------------ forms */

  function submitForm(form, action, onSuccess) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var data = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name) {
          return;
        }
        if (el.type === 'checkbox') {
          data[el.name] = el.checked ? '1' : '0';
          return;
        }
        data[el.name] = el.value;
      });

      post(action, data).then(function (result) {
        var payload = parseResult(result);
        if (payload.ok) {
          toast(payload.message || 'Saved.', 'ok');
          if (typeof onSuccess === 'function') {
            onSuccess(payload);
          }
        } else {
          toast(payload.message || 'Save failed.', 'error');
        }
      }).catch(function () {
        toast('Request failed.', 'error');
      });
    });
  }

  var general = document.getElementById('form-general');
  if (general) {
    submitForm(general, 'saveGeneral');
  }

  var devices = document.getElementById('form-devices');
  if (devices) {
    devices.addEventListener('submit', function (event) {
      event.preventDefault();
      var gpus = Array.prototype.map.call(devices.querySelectorAll('input[name="gpu[]"]:checked'), function (el) { return el.value; });
      var usb = Array.prototype.map.call(devices.querySelectorAll('input[name="usb[]"]:checked'), function (el) { return el.value; });
      var misc = Array.prototype.map.call(devices.querySelectorAll('input[name="misc[]"]:checked'), function (el) { return el.value; });

      post('saveDevices', {
        gpus: JSON.stringify(gpus),
        usb: JSON.stringify(usb),
        misc: JSON.stringify(misc)
      }).then(function (result) {
        var payload = parseResult(result);
        if (payload.ok) {
          toast(payload.message || 'Devices saved.', 'ok');
          if (EDIT.state === 'RUNNING') {
            toast('Devices saved. Restart the container to apply them.', 'info');
          }
        } else {
          toast(payload.message || 'Save failed.', 'error');
        }
      }).catch(function () {
        toast('Request failed.', 'error');
      });
    });
  }

  var ssh = document.getElementById('form-ssh');
  if (ssh) {
    submitForm(ssh, 'saveSsh', function (payload) {
      var messages = payload.messages || [];
      if (messages.length) {
        toast(messages.join(' '), payload.ok ? 'ok' : 'error');
      }
      ssh.querySelector('input[name="rootPassword"]').value = '';
      ssh.querySelector('input[name="userPassword"]').value = '';
    });
  }

  var advanced = document.getElementById('form-advanced');
  if (advanced) {
    submitForm(advanced, 'saveRawConfig', function () {
      window.setTimeout(function () { window.location.reload(); }, 600);
    });
  }

  /* ------------------------------------------------------------------- init */

  renderNics();

  var initialTab = (window.location.hash || '').replace('#', '');
  if (initialTab && document.getElementById('tab-' + initialTab)) {
    activateTab(initialTab);
  }

  }

  // Defensive: the page loads this file at the end of the body, but keep the
  // handler working even if it ends up in <head> after a theme/custom change.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
