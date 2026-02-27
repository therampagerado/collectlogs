<div class="col-lg-12">

    <div class="panel">
        <h3>{$row.error_type|escape:'html'}</h3>
        <div class="panel-body">
            <p>
                <h4>{l s='Severity:' mod='collectlogs'}</h4>
                {if $row.severity == 'fatal'}
                    <span class="badge badge-critical">{$row.severity|escape:'html'}</span>
                {elseif $row.severity == 'error'}
                    <span class="badge badge-danger">{$row.severity|escape:'html'}</span>
                {elseif $row.severity == 'warn'}
                    <span class="badge badge-warning">{$row.severity|escape:'html'}</span>
                {else}
                    <span class="badge badge-info">{$row.severity|escape:'html'}</span>
                {/if}
            </p>
            <br />
            <p>
                <h4>{l s='Message:' mod='collectlogs'}</h4>
                <code>{$row.message|escape:'html'}</code>
            </p>
            <br />
            <p>
                <h4>{l s='Location:' mod='collectlogs'}</h4>
                {if $row.script_url}
                    <code>{$row.script_url|escape:'html'}</code>
                    {if $row.line}
                        &nbsp;line&nbsp;<code>{$row.line|intval}{if $row.col}:{$row.col|intval}{/if}</code>
                    {/if}
                {else}
                    {l s='Unknown' mod='collectlogs'}
                {/if}
            </p>
            <br />
            <p>
                <h4>{l s='Page URL:' mod='collectlogs'}</h4>
                <a href="{$row.url|escape:'html'}" target="_blank" rel="noreferrer noopener">{$row.url|escape:'html'|truncate:120:'...'}</a>
                {if $row.referrer}
                    <br />
                    <small>{l s='Referrer:' mod='collectlogs'} {$row.referrer|escape:'html'|truncate:120:'...'}</small>
                {/if}
            </p>
            <br />
            <p>
                <h4>{l s='Statistics:' mod='collectlogs'}</h4>
                {l s='Occurrences:' mod='collectlogs'} <strong>{$row.occurrences|intval}</strong>
                &nbsp;&mdash;&nbsp;
                {l s='First seen:' mod='collectlogs'} {$row.first_seen|escape:'html'}
                &nbsp;&mdash;&nbsp;
                {l s='Last seen:' mod='collectlogs'} {$row.last_seen|escape:'html'}
            </p>
            <br />
            <p>
                <h4>{l s='Fingerprint:' mod='collectlogs'}</h4>
                <code>{$row.fingerprint|escape:'html'}</code>
            </p>
        </div>
    </div>

    {if $row.user_agent}
    <div class="panel">
        <h3>{l s='User Agent' mod='collectlogs'}</h3>
        <div class="panel-body">
            <pre><code>{$row.user_agent|escape:'html'}</code></pre>
        </div>
    </div>
    {/if}

    {if $stackFrames}
    <div class="panel">
        <h3>{l s='Stack Trace' mod='collectlogs'}</h3>
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

    {if $tags}
    <div class="panel">
        <h3>{l s='Page Context Tags' mod='collectlogs'}</h3>
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

    <div class="panel">
        <h3>{l s='Raw Stack Trace JSON' mod='collectlogs'}</h3>
        <div class="panel-body">
            <pre style="max-height:400px;overflow:auto"><code>{$row.stack_trace_json|default:'{}'|escape:'html'}</code></pre>
        </div>
    </div>

</div>
