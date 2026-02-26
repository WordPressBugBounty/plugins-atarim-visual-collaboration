class ConsentFormHandler {
    constructor() {
        this.initializeEventListeners();
    }

    initializeEventListeners() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.avc_consent_form_launcher')) {
                this.showConsentForm(e.target.closest('.avc_consent_form_launcher'));
            }
        });

        // Close consent form
        document.addEventListener('click', (e) => {
            if (e.target.closest('.avc_user_consent_close')) {
                this.hideConsentForm();
            }
        });

        // Handle consent submission
        document.addEventListener('click', (e) => {
            if (e.target.closest('.avc_user_consent_button')) {
                this.handleConsentSubmission(e.target.closest('.avc_user_consent_button'));
            }
        });
    }

    showConsentForm(launcher) {
        launcher.classList.add('avc_hide_launcher');
        document.querySelector('.avc_user_consent_container')?.classList.add('avc_show');
    }

    hideConsentForm() {
        document.querySelector('.avc_user_consent_container')?.classList.remove('avc_show');
    }

    async handleConsentSubmission(button) {
        try {
            this.setLoadingState(button, true);

            const firstResponse = await this.submitUserConsent();

            if (firstResponse?.data) {
                await this.makeChainedRequest(firstResponse.data);
                this.redirectWithConsentParam();
            } else {
                throw new Error('Invalid response from consent submission');
            }
        } catch (error) {
            console.error('Consent submission failed:', error);
            alert('Something went wrong. Please try again.');
        } finally {
            this.setLoadingState(button, false);
        }
    }

    async submitUserConsent() {
        const formData = new FormData();
        formData.append('action', 'avcf_user_consent');
        formData.append('avc_nonce', window.avc_site_data?.avc_nonce || '');

        const response = await fetch(window.avcajax?.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return response.json();
    }

    async makeChainedRequest(data) {
        const response = await fetch(data.apiurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'api-key': data.apikey,
            },
            credentials: 'include',
            body: JSON.stringify(data),
        });

        if (!response.ok) {
            throw new Error(`Chained request failed! status: ${response.status}`);
        }

        const result = await response.json();

        if (!result) {
            throw new Error('Second request returned invalid response');
        }

        await this.setConsentStatusOnServer();

        return result;
    }

    redirectWithConsentParam() {
        const currentUrl = new URL(window.location.href);
        window.location.href = currentUrl.toString();
    }

    setLoadingState(button, isLoading) {
        const loader = document.querySelector('.avc_consent_loader');

        if (isLoading) {
            button.classList.add('loading');
            loader?.style.setProperty('display', 'block');
        } else {
            button.classList.remove('loading');
            loader?.style.setProperty('display', 'none');
        }
    }

    async setConsentStatusOnServer() {
        const formData = new FormData();
        formData.append('action', 'avcf_set_user_consent_status');
        formData.append('avc_nonce', window.avc_site_data?.avc_nonce || '');

        const response = await fetch(window.avcajax?.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'include',
        });

        if (!response.ok) {
            throw new Error(`Failed to update user meta. Status: ${response.status}`);
        }

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.data?.message || 'Unknown error from consent meta update');
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new ConsentFormHandler();
    });
} else {
    new ConsentFormHandler();
}