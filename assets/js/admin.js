/**
 * LLMs.txt Generator - Admin JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    const clearButton = document.getElementById('llms-clear-cache');
    const messageEl = document.getElementById('llms-cache-message');

    if (!clearButton || !messageEl) {
        return;
    }

    clearButton.addEventListener('click', async () => {
        clearButton.disabled = true;
        clearButton.textContent = llmsTxtAdmin.strings.clearing || 'Clearing...';
        messageEl.style.display = 'none';

        try {
            const response = await fetch(llmsTxtAdmin.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'llms_txt_clear_cache',
                    nonce: llmsTxtAdmin.nonce,
                }),
            });

            const data = await response.json();

            if (data.success) {
                messageEl.textContent = llmsTxtAdmin.strings.cacheCleared;
                messageEl.className = 'llms-txt-message success';
            } else {
                messageEl.textContent = data.data?.message || llmsTxtAdmin.strings.error;
                messageEl.className = 'llms-txt-message error';
            }
            messageEl.style.display = 'inline-flex';
        } catch (error) {
            messageEl.textContent = llmsTxtAdmin.strings.error;
            messageEl.className = 'llms-txt-message error';
            messageEl.style.display = 'inline-flex';
        } finally {
            clearButton.disabled = false;
            clearButton.textContent = llmsTxtAdmin.strings.clearCache || 'Clear Cache';

            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 4000);
        }
    });
});
