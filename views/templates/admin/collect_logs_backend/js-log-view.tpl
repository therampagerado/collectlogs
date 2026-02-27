<div class="panel">
    <h3>JS Error #{$row.id_collectlogs_js_error|intval}</h3>
    <p><strong>Severity:</strong> {$row.severity|escape:'html':'UTF-8'}</p>
    <p><strong>Status:</strong> {$row.status|escape:'html':'UTF-8'}</p>
    <p><strong>Message:</strong> {$row.message|escape:'html':'UTF-8'}</p>
    <p><strong>URL:</strong> {$row.url|escape:'html':'UTF-8'}</p>
    <p><strong>Referrer:</strong> {$row.referrer|escape:'html':'UTF-8'}</p>
    <p><strong>User agent:</strong> {$row.user_agent|escape:'html':'UTF-8'}</p>
    <p><strong>Occurrences:</strong> {$row.occurrences|intval}</p>
    <p><strong>First seen:</strong> {$row.first_seen|escape:'html':'UTF-8'}</p>
    <p><strong>Last seen:</strong> {$row.last_seen|escape:'html':'UTF-8'}</p>

    <h4>Stack trace</h4>
    {if $stack}
        <ol>
            {foreach $stack as $frame}
                <li>
                    <code>{$frame.func|escape:'html':'UTF-8'}</code>
                    <span>{$frame.url|escape:'html':'UTF-8'}</span>
                    {if isset($frame.line)}:<span>{$frame.line|intval}</span>{/if}
                    {if isset($frame.column)}:<span>{$frame.column|intval}</span>{/if}
                </li>
            {/foreach}
        </ol>
    {else}
        <p>No stack trace available.</p>
    {/if}

    <h4>Raw payload (extra_json)</h4>
    <pre>{$row.extra_json|escape:'html':'UTF-8'}</pre>

    <a class="btn btn-warning" href="{$markIgnoredUrl|escape:'html':'UTF-8'}">Mark ignored</a>
    <a class="btn btn-success" href="{$markResolvedUrl|escape:'html':'UTF-8'}">Mark resolved</a>
</div>
