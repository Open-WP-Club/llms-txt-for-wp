/**
 * LLMs.txt Generator - Admin JavaScript
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('llmsTxtSettings', () => ({
        isClearing: false,
        message: '',
        isError: false,

        async clearCache() {
            this.isClearing = true;
            this.message = '';

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
                    this.message = llmsTxtAdmin.strings.cacheCleared;
                    this.isError = false;
                } else {
                    this.message = data.data?.message || llmsTxtAdmin.strings.error;
                    this.isError = true;
                }
            } catch (error) {
                this.message = llmsTxtAdmin.strings.error;
                this.isError = true;
            } finally {
                this.isClearing = false;

                setTimeout(() => {
                    this.message = '';
                }, 4000);
            }
        }
    }));
});
