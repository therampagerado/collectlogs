{assign var='severityClass' value='info'}
{if $log.severity == 'fatal' || $log.severity == 'error'}
    {assign var='severityClass' value='danger'}
{elseif $log.severity == 'warn'}
    {assign var='severityClass' value='warning'}
{/if}

<div class="col-lg-12">
    <div class="panel">
        <h3>JS Error #{$log.id_collectlogs_js_error|intval}</h3>
        <div class="table-responsive">
            <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                <tbody>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Severity</td>
                        <td>
                            <span class="label label-{$severityClass|escape:'html':'UTF-8'}">
                                {$log.severity|escape:'html':'UTF-8'}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Error type</td>
                        <td><code style="white-space: normal; word-break: break-word;">{$log.error_type|escape:'html':'UTF-8'}</code></td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Message</td>
                        <td><code style="white-space: normal; word-break: break-word;">{$log.message|escape:'html':'UTF-8'}</code></td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Page URL</td>
                        <td>
                            {if $log.url}
                                <a href="{$log.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">{$log.url_display|default:$log.url|escape:'html':'UTF-8'}</a>
                            {else}
                                <span class="text-muted">n/a</span>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Referrer</td>
                        <td>
                            {if $log.referrer}
                                <a href="{$log.referrer|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">{$log.referrer_display|default:$log.referrer|escape:'html':'UTF-8'}</a>
                            {else}
                                <span class="text-muted">n/a</span>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Script URL</td>
                        <td>
                            {if $log.script_url}
                                <a href="{$log.script_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">{$log.script_url_display|default:$log.script_url|escape:'html':'UTF-8'}</a>
                            {else}
                                <span class="text-muted">n/a</span>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Line / Column</td>
                        <td>
                            {if $log.line || $log.column}
                                <code>{$log.line|intval}{if $log.column}:{$log.column|intval}{/if}</code>
                            {else}
                                <span class="text-muted">n/a</span>
                            {/if}
                        </td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Occurrences</td>
                        <td>{$log.occurrences|intval}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">First seen</td>
                        <td>{$log.first_seen|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Last seen</td>
                        <td>{$log.last_seen|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Shop ID</td>
                        <td>{$log.id_shop|intval}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Language ID</td>
                        <td>{if $log.id_lang}{$log.id_lang|intval}{else}<span class="text-muted">n/a</span>{/if}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Customer ID</td>
                        <td>{if $log.id_customer}{$log.id_customer|intval}{else}<span class="text-muted">guest / anonymous</span>{/if}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Visitor ID</td>
                        <td>{if $log.visitor_id}<code>{$log.visitor_id|escape:'html':'UTF-8'}</code>{else}<span class="text-muted">n/a</span>{/if}</td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">User agent</td>
                        <td><code style="white-space: normal; word-break: break-word;">{$log.user_agent|escape:'html':'UTF-8'}</code></td>
                    </tr>
                    <tr>
                        <td style="width: 220px; font-weight: 600;">Fingerprint</td>
                        <td><code style="word-break: break-all;">{$log.fingerprint|escape:'html':'UTF-8'}</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {if $meta}
        <div class="panel">
            <h3>Captured metadata</h3>
            <div class="alert alert-info">
                <strong>Metadata note:</strong>
                <ul style="margin: 8px 0 0 18px;">
                    <li><code>source_kind</code> tells whether the error came from an external script, an inline/document context, or a resource load.</li>
                    <li><code>wrapped_function</code> is the function label captured by the optional client wrapper helper.</li>
                    <li><code>argument_summary</code> contains only argument shapes and counts, never raw argument values.</li>
                </ul>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                    <tbody>
                        {foreach $meta as $metaKey => $metaValue}
                            {if $metaKey ne 'runtime_state' && $metaKey ne 'source_excerpt'}
                                <tr>
                                    <td style="width: 220px; font-weight: 600;">{$metaKey|escape:'html':'UTF-8'}</td>
                                    <td>
                                        {if is_array($metaValue)}
                                            <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word;">{json_encode($metaValue, 128)|escape:'html':'UTF-8'}</pre>
                                        {elseif is_bool($metaValue)}
                                            <code>{if $metaValue}true{else}false{/if}</code>
                                        {elseif is_numeric($metaValue) || (isset($metaValue) && $metaValue ne '')}
                                            <code>{$metaValue|escape:'html':'UTF-8'}</code>
                                        {else}
                                            <span class="text-muted">n/a</span>
                                        {/if}
                                    </td>
                                </tr>
                            {/if}
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    {/if}

    {if $runtimeState}
        <div class="panel">
            <h3>Runtime snapshot</h3>
            <div class="table-responsive">
                <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                    <tbody>
                        {foreach $runtimeState as $runtimeKey => $runtimeValue}
                            <tr>
                                <td style="width: 220px; font-weight: 600;">{$runtimeKey|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if is_array($runtimeValue)}
                                        <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word;">{json_encode($runtimeValue, 128)|escape:'html':'UTF-8'}</pre>
                                    {elseif is_bool($runtimeValue)}
                                        <code>{if $runtimeValue}true{else}false{/if}</code>
                                    {elseif is_numeric($runtimeValue) || (isset($runtimeValue) && $runtimeValue ne '')}
                                        <code>{$runtimeValue|escape:'html':'UTF-8'}</code>
                                    {else}
                                        <span class="text-muted">n/a</span>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    {/if}

    {if $tags}
        <div class="panel">
            <h3>Tags</h3>
            <div class="table-responsive">
                <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                    <tbody>
                        {foreach $tags as $tagKey => $tagValue}
                            {if $tagKey ne 'tb_version' && $tagKey ne 'tb_revision' && $tagKey ne 'module_version' && $tagKey ne 'theme'}
                                <tr>
                                    <td style="width: 220px; font-weight: 600;">{$tagKey|escape:'html':'UTF-8'}</td>
                                    <td>
                                        {if is_array($tagValue)}
                                            <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word;">{json_encode($tagValue, 128)|escape:'html':'UTF-8'}</pre>
                                        {elseif is_bool($tagValue)}
                                            <code>{if $tagValue}true{else}false{/if}</code>
                                        {elseif is_numeric($tagValue) || (isset($tagValue) && $tagValue ne '')}
                                            <code>{$tagValue|escape:'html':'UTF-8'}</code>
                                        {else}
                                            <span class="text-muted">n/a</span>
                                        {/if}
                                    </td>
                                </tr>
                            {/if}
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    {/if}

    {if isset($sourceExcerpt.focus) && $sourceExcerpt.focus}
        <div class="panel">
            <h3>Source context</h3>
            <div class="table-responsive">
                <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                    <tbody>
                        {if isset($sourceExcerpt.source_url) && $sourceExcerpt.source_url}
                            <tr>
                                <td style="width: 220px; font-weight: 600;">Source URL</td>
                                <td>
                                    <a href="{$sourceExcerpt.source_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">{$sourceExcerpt.source_url_display|default:$sourceExcerpt.source_url|escape:'html':'UTF-8'}</a>
                                </td>
                            </tr>
                        {/if}
                        <tr>
                            <td style="width: 220px; font-weight: 600;">Location</td>
                            <td>
                                <code>{$sourceExcerpt.line|intval}{if isset($sourceExcerpt.column) && $sourceExcerpt.column}:{$sourceExcerpt.column|intval}{/if}</code>
                            </td>
                        </tr>
                        <tr>
                            <td style="width: 220px; font-weight: 600;">Excerpt</td>
                            <td>
                                <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word; line-height: 1.5;">{if !empty($sourceExcerpt.has_prefix)}...{/if}{$sourceExcerpt.before|escape:'html':'UTF-8'}<strong style="color: #c2185b;">{$sourceExcerpt.focus|escape:'html':'UTF-8'}</strong>{$sourceExcerpt.after|escape:'html':'UTF-8'}{if !empty($sourceExcerpt.has_suffix)}...{/if}</pre>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    {/if}

    <div class="panel">
        <h3>Stack trace</h3>
        {if $stackFrames}
            <div class="table-responsive">
                <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th style="width: 240px;">Function</th>
                            <th>Location</th>
                            <th style="width: 220px;">Arguments</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $stackFrames as $index => $frame}
                            <tr>
                                <td><code>{$index|intval}</code></td>
                                <td>
                                    {if isset($frame.func) && $frame.func ne ''}
                                        <code>{$frame.func|escape:'html':'UTF-8'}</code>
                                    {else}
                                        <span class="text-muted">anonymous</span>
                                    {/if}
                                </td>
                                <td>
                                    {if isset($frame.url) && $frame.url}
                                        <a href="{$frame.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">{$frame.url_display|default:$frame.url|escape:'html':'UTF-8'}</a>
                                    {elseif isset($frame.file) && $frame.file}
                                        <code>{$frame.file|escape:'html':'UTF-8'}</code>
                                    {else}
                                        <span class="text-muted">n/a</span>
                                    {/if}
                                    {if isset($frame.line) && $frame.line}
                                        <div style="margin-top: 6px;">
                                            <code>{$frame.line|intval}{if isset($frame.column) && $frame.column}:{$frame.column|intval}{/if}</code>
                                        </div>
                                    {/if}
                                </td>
                                <td>
                                    {if isset($frame.args) && is_array($frame.args) && count($frame.args) > 0}
                                        <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word;">{json_encode($frame.args, 128)|escape:'html':'UTF-8'}</pre>
                                    {else}
                                        <span class="text-muted">n/a</span>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="text-muted" style="margin-bottom: 0;">No stack trace captured.</p>
        {/if}
    </div>

    <div class="panel">
        <h3>Additional payload</h3>
        <div class="table-responsive">
            <table class="table table-bordered table-striped" style="margin-bottom: 0;">
                <tbody>
                    {assign var='hasAdditionalPayload' value=false}
                    {foreach $extra as $extraKey => $extraValue}
                        {if $extraKey != 'meta' && $extraKey != 'tags' && $extraKey != 'source_excerpt' && $extraKey != 'breadcrumbs'}
                            {assign var='hasAdditionalPayload' value=true}
                            <tr>
                                <td style="width: 220px; font-weight: 600;">{$extraKey|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if is_array($extraValue)}
                                        <pre class="well well-sm" style="margin: 0; white-space: pre-wrap; word-break: break-word;">{json_encode($extraValue, 128)|escape:'html':'UTF-8'}</pre>
                                    {elseif is_bool($extraValue)}
                                        <code>{if $extraValue}true{else}false{/if}</code>
                                    {elseif is_numeric($extraValue) || (isset($extraValue) && $extraValue ne '')}
                                        <code>{$extraValue|escape:'html':'UTF-8'}</code>
                                    {else}
                                        <span class="text-muted">n/a</span>
                                    {/if}
                                </td>
                            </tr>
                        {/if}
                    {/foreach}
                    {if !$hasAdditionalPayload}
                        <tr>
                            <td colspan="2"><span class="text-muted">No additional payload captured.</span></td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </div>
</div>
