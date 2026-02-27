<div class="col-lg-12">

  {* ── Summary panel ──────────────────────────────────────────────────────── *}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-warning-sign"></i>
      {l s='JS Error Detail' mod='collectlogs'}
    </div>
    <div class="panel-body">

      <table class="table">
        <tbody>
          <tr>
            <th style="width:160px">{l s='Severity' mod='collectlogs'}</th>
            <td>
              {if $row.severity == 'fatal'}
                <span class="badge badge-critical">{$row.severity|escape:'html'}</span>
              {elseif $row.severity == 'error'}
                <span class="badge badge-danger">{$row.severity|escape:'html'}</span>
              {elseif $row.severity == 'warn'}
                <span class="badge badge-warning">{$row.severity|escape:'html'}</span>
              {else}
                <span class="badge badge-info">{$row.severity|escape:'html'}</span>
              {/if}
            </td>
          </tr>
          <tr>
            <th>{l s='Error type' mod='collectlogs'}</th>
            <td><code>{$row.error_type|escape:'html'}</code></td>
          </tr>
          <tr>
            <th>{l s='Message' mod='collectlogs'}</th>
            <td><code>{$row.message|escape:'html'}</code></td>
          </tr>
          <tr>
            <th>{l s='Page URL' mod='collectlogs'}</th>
            <td><a href="{$row.url|escape:'html'}" target="_blank" rel="noreferrer noopener">{$row.url|escape:'html'|truncate:120:'...'}</a></td>
          </tr>
          {if $row.referrer}
          <tr>
            <th>{l s='Referrer' mod='collectlogs'}</th>
            <td>{$row.referrer|escape:'html'|truncate:120:'...'}</td>
          </tr>
          {/if}
          {if $row.script_url}
          <tr>
            <th>{l s='Script URL' mod='collectlogs'}</th>
            <td><code>{$row.script_url|escape:'html'|truncate:120:'...'}</code></td>
          </tr>
          {/if}
          {if $row.line}
          <tr>
            <th>{l s='Line / Column' mod='collectlogs'}</th>
            <td><code>{$row.line|intval}{if $row.col}:{$row.col|intval}{/if}</code></td>
          </tr>
          {/if}
          <tr>
            <th>{l s='Occurrences' mod='collectlogs'}</th>
            <td><strong>{$row.occurrences|intval}</strong></td>
          </tr>
          <tr>
            <th>{l s='First seen' mod='collectlogs'}</th>
            <td>{$row.first_seen|escape:'html'}</td>
          </tr>
          <tr>
            <th>{l s='Last seen' mod='collectlogs'}</th>
            <td>{$row.last_seen|escape:'html'}</td>
          </tr>
          <tr>
            <th>{l s='Shop ID' mod='collectlogs'}</th>
            <td>{$row.id_shop|intval}</td>
          </tr>
          <tr>
            <th>{l s='Fingerprint' mod='collectlogs'}</th>
            <td><code>{$row.fingerprint|escape:'html'}</code></td>
          </tr>
        </tbody>
      </table>

    </div>
  </div>

  {* ── User agent ──────────────────────────────────────────────────────────── *}
  {if $row.user_agent}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-desktop"></i>
      {l s='User Agent' mod='collectlogs'}
    </div>
    <div class="panel-body">
      <code>{$row.user_agent|escape:'html'}</code>
    </div>
  </div>
  {/if}

  {* ── Stack trace ─────────────────────────────────────────────────────────── *}
  {if $stackFrames}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-code"></i>
      {l s='Stack Trace' mod='collectlogs'}
    </div>
    <div class="panel-body">
      <table class="table table-striped table-condensed">
        <thead>
          <tr>
            <th>#</th>
            <th>{l s='Function' mod='collectlogs'}</th>
            <th>{l s='File' mod='collectlogs'}</th>
            <th>{l s='Line' mod='collectlogs'}</th>
            <th>{l s='Column' mod='collectlogs'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach $stackFrames as $idx => $frame}
          <tr>
            <td>{$idx|intval}</td>
            <td><code>{$frame.func|default:'<anonymous>'|escape:'html'}</code></td>
            <td><code title="{$frame.url|default:''|escape:'html'}">{$frame.url|default:''|escape:'html'|truncate:80:'...'}</code></td>
            <td>{$frame.line|default:''|escape:'html'}</td>
            <td>{$frame.col|default:''|escape:'html'}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {/if}

  {* ── Tags / meta ─────────────────────────────────────────────────────────── *}
  {if $tags}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-tags"></i>
      {l s='Page Context Tags' mod='collectlogs'}
    </div>
    <div class="panel-body">
      <table class="table table-condensed">
        <tbody>
          {foreach $tags as $key => $val}
          <tr>
            <th style="width:160px"><code>{$key|escape:'html'}</code></th>
            <td>{$val|escape:'html'}</td>
          </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {/if}

  {* ── Raw JSON ────────────────────────────────────────────────────────────── *}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-file-text"></i>
      {l s='Raw Stack Trace JSON' mod='collectlogs'}
    </div>
    <div class="panel-body">
      <pre style="max-height:400px;overflow:auto"><code>{$row.stack_trace_json|default:'{}'|escape:'html'}</code></pre>
    </div>
  </div>

</div>
