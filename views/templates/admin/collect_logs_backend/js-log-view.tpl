<div class="panel">
    <h3>JS Error #{$log.id_collectlogs_js_error|intval}</h3>
    <p><strong>Severity:</strong> {$log.severity|escape:'html':'UTF-8'}</p>
    <p><strong>Type:</strong> {$log.error_type|escape:'html':'UTF-8'}</p>
    <p><strong>Message:</strong> {$log.message|escape:'html':'UTF-8'}</p>
    <p><strong>URL:</strong> {$log.url|escape:'html':'UTF-8'}</p>
    <p><strong>Referrer:</strong> {$log.referrer|escape:'html':'UTF-8'}</p>
    <p><strong>User agent:</strong> {$log.user_agent|escape:'html':'UTF-8'}</p>
    <p><strong>Fingerprint:</strong> {$log.fingerprint|escape:'html':'UTF-8'}</p>
    <h4>Stack trace</h4>
    <pre>{json_encode($stackFrames, 128)|escape:'html':'UTF-8'}</pre>
    <h4>Extra</h4>
    <pre>{json_encode($extra, 128)|escape:'html':'UTF-8'}</pre>
</div>
