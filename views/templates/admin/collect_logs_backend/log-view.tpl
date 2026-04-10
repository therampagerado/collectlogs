<textarea id="collectlogs-markdown-copy-source" readonly="readonly" style="position:absolute; left:-9999px; top:-9999px;">{$markdownBody|escape:'html':'UTF-8'}</textarea>
<script type="text/javascript">
if (typeof window.collectlogsCopyMarkdown !== 'function') {
    window.collectlogsCopyMarkdown = function (sourceId, successMessage, failureMessage) {
        var source = document.getElementById(sourceId);
        var text = source ? source.value : '';

        function notify(kind, message) {
            if (kind === 'success' && typeof window.showSuccessMessage === 'function') {
                window.showSuccessMessage(message);
                return false;
            }
            if (kind === 'error' && typeof window.showErrorMessage === 'function') {
                window.showErrorMessage(message);
                return false;
            }
            alert(message);
            return false;
        }

        if (!text) {
            return notify('error', failureMessage);
        }

        function notifySuccess() {
            return notify('success', successMessage);
        }

        function fallbackCopy() {
            try {
                source.focus();
                source.select();
                source.setSelectionRange(0, source.value.length);
                if (document.execCommand('copy')) {
                    return notifySuccess();
                }
            } catch (e) {}
            return notify('error', failureMessage);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(notifySuccess).catch(fallbackCopy);
            return false;
        }

        return fallbackCopy();
    };
}
</script>

<div class="col-lg-12">
    <div class="panel">
        <h3>{$type}</h3>
        <div class="panel-body">
            <p>
                <h4>{l s='Message:' mod='collectlogs'}</h4>
                <code>{$generic_message|escape:'html'}</code>
                {if $generic_message != $sample_message}
                    <br />
                    <code>{$sample_message|escape:'html'}</code>
                {/if}
            </p>

            <br />

            <p>
                <h4>{l s='Location:' mod='collectlogs'}</h4>
                {if $real_file}
                    <code>{$file}</code>
                    <br />
                    <code>{$real_file}</code>&nbsp;line&nbsp;<code>{$real_line}</code>
                {else}
                    <code>{$file}</code>&nbsp;line&nbsp;<code>{$line}</code>
                {/if}
            </p>
        </div>
    </div>
    {foreach $extraSections as $section}
        <div class="panel">
            <h3>{$section.label|escape:'html'}</h3>
            <div class="panel-body">
                <pre><code>{$section.content|escape:'html'}</code></pre>
            </div>
        </div>

    {/foreach}
</div>
