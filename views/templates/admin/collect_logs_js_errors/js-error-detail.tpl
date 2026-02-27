<div class="row">
    <div class="col-lg-12">

        {assign var='severityClass' value='label-info'}
        {if $row.severity == 'fatal'}
            {assign var='severityClass' value='label-danger'}
        {elseif $row.severity == 'error'}
            {assign var='severityClass' value='label-danger'}
        {elseif $row.severity == 'warn'}
            {assign var='severityClass' value='label-warning'}
        {/if}

        <div class="panel panel-default">
            <div class="panel-heading">
                <i class="icon-warning-sign"></i>
                {l s='JS Error Detail' mod='collectlogs'}
            </div>
            <div class="panel-body">
                <table class="table table-bordered table-striped table-condensed">
                    <tbody>
                        <tr>
                            <th>{l s='Severity' mod='collectlogs'}</th>
                            <td><span class="label {$severityClass}">{$row.severity|escape:'html'}</span></td>
                        </tr>
                        <tr>
                            <th>{l s='Error type' mod='collectlogs'}</th>
                            <td><code>{$row.error_type|default:'-'|escape:'html'}</code></td>
                        </tr>
                        <tr>
                            <th>{l s='Message' mod='collectlogs'}</th>
                            <td><code>{$row.message|default:'-'|escape:'html'}</code></td>
                        </tr>
                        <tr>
                            <th>{l s='Page URL' mod='collectlogs'}</th>
                            <td>
                                {if $row.url}
                                    <a href="{$row.url|escape:'html'}" target="_blank" rel="noreferrer noopener">{$row.url|escape:'html'}</a>
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Referrer' mod='collectlogs'}</th>
                            <td>{$row.referrer|default:'-'|escape:'html'}</td>
                        </tr>
                        <tr>
                            <th>{l s='Script URL' mod='collectlogs'}</th>
                            <td>
                                {if $row.script_url}
                                    <code>{$row.script_url|escape:'html'}</code>
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Line / Column' mod='collectlogs'}</th>
                            <td>
                                {if $row.line}
                                    <code>{$row.line|intval}{if $row.col}:{$row.col|intval}{/if}</code>
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Occurrences' mod='collectlogs'}</th>
                            <td>{$row.occurrences|intval}</td>
                        </tr>
                        <tr>
                            <th>{l s='First seen' mod='collectlogs'}</th>
                            <td>{$row.first_seen|default:'-'|escape:'html'}</td>
                        </tr>
                        <tr>
                            <th>{l s='Last seen' mod='collectlogs'}</th>
                            <td>{$row.last_seen|default:'-'|escape:'html'}</td>
                        </tr>
                        <tr>
                            <th>{l s='Shop ID' mod='collectlogs'}</th>
                            <td>{$row.id_shop|intval}</td>
                        </tr>
                        <tr>
                            <th>{l s='Fingerprint' mod='collectlogs'}</th>
                            <td><code>{$row.fingerprint|default:'-'|escape:'html'}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {if $row.user_agent}
            <div class="panel panel-default">
                <div class="panel-heading">{l s='User Agent' mod='collectlogs'}</div>
                <div class="panel-body">
                    <pre><code>{$row.user_agent|escape:'html'}</code></pre>
                </div>
            </div>
        {/if}

        {if $stackFrames}
            <div class="panel panel-default">
                <div class="panel-heading">{l s='Stack Trace' mod='collectlogs'}</div>
                <div class="panel-body">
                    <table class="table table-bordered table-striped table-condensed">
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
                                    <td><code title="{$frame.url|default:''|escape:'html'}">{$frame.url|default:'-'|escape:'html'|truncate:80:'...'}</code></td>
                                    <td>{$frame.line|default:'-'|escape:'html'}</td>
                                    <td>{$frame.col|default:'-'|escape:'html'}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        {/if}

        {if $tags}
            <div class="panel panel-default">
                <div class="panel-heading">{l s='Page Context Tags' mod='collectlogs'}</div>
                <div class="panel-body">
                    <table class="table table-bordered table-striped table-condensed">
                        <tbody>
                            {foreach $tags as $key => $val}
                                <tr>
                                    <th><code>{$key|escape:'html'}</code></th>
                                    <td>{$val|default:'-'|escape:'html'}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        {/if}

        <div class="panel panel-default">
            <div class="panel-heading">{l s='Raw Stack Trace JSON' mod='collectlogs'}</div>
            <div class="panel-body">
                <pre><code>{$row.stack_trace_json|default:'{}'|escape:'html'}</code></pre>
            </div>
        </div>

    </div>
</div>
