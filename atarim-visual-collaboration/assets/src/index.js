import { useState } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { SelectControl, Button } from '@wordpress/components';
import CreatableSelect from 'react-select/creatable';
import ActivateDeactivateButton from './ActivateDeactivateButton';
import Notice from './Notice';

const CollaborationSettings = () => {
    const [settings, setSettings] = useState(window.avcSettings.settings);
    const [successMessage, setSuccessMessage] = useState('');
    const [copied, setCopied] = useState(false);

    const saveAllSettings = async () => {
        const response = await fetch(`${window.avcSettings.ajaxurl}?action=avcf_save_settings`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-AVC-Nonce': window.avcSettings.avc_nonce,
            },
            body: JSON.stringify(settings),
        });

        if (response.ok) {
            const result = await response.json();
            if (result.success) {
                setSuccessMessage('Settings saved successfully!');
                setTimeout(() => setSuccessMessage(''), 3000);
            } else {
                alert('Failed to save settings: ' + result.data.message);
            }
        } else {
            alert('Error saving settings. Please try again.');
        }
    };

    const updateSetting = (field, value) => {
        setSettings((prev) => ({ ...prev, [field]: value }));
    };

    const handleCopy = () => {
        const textToCopy = window.avcSettings.avc_collab_link;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy)
                .then(() => {
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2000);
                })
                .catch((err) => {
                    console.error('Copy failed:', err);
                    fallbackCopy(textToCopy);
                });
        } else {
            fallbackCopy(textToCopy);
        }
    };

    const fallbackCopy = (text) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            console.error('Fallback: Copy failed', err);
            alert('Failed to copy the link.');
        }
        document.body.removeChild(textarea);
    };

    return (
        <div className="avc-settings-layout">
            <div className="avc_setting_parent">
                <div className="avc-setting-block">
                    <img src={window.avcSettings.logoUrl} alt="Atarim Logo" style={{ maxWidth: '120px', marginBottom: '10px' }} />

                    {window.avcSettings.isCollabActive === 'yes' ? (
                        <>
                            <h3>{window.avcSettings.i18n.connected}</h3>
                            <p dangerouslySetInnerHTML={{ __html: window.avcSettings.i18n.subheader }} />
                        </>
                    ) : (
                        <>
                            <h3>{window.avcSettings.i18n.connectHeading}</h3>
                            <p>{window.avcSettings.i18n.connectDescription}</p>
                            <p dangerouslySetInnerHTML={{ __html: window.avcSettings.i18n.connectCta }} />
                        </>
                    )}
                </div>

                <div className="avc-setting-block">
                    <ActivateDeactivateButton
                        isCollabActive={window.avcSettings.isCollabActive}
                        activationUrl={window.avcSettings.activationUrl}
                    />
                </div>

                {window.avcSettings.isCollabActive === 'yes' && (
                    <>
                        <div className="avc-setting-block avc-guest-mode">
                            <label className="components-base-control__label avc-label-with-tooltip">
                                <span>{window.avcSettings.i18n.howTo}</span>
                                <span className="avc-tooltip">
                                    <button
                                        type="button"
                                        className="avc-tooltip-trigger"
                                        aria-label={window.avcSettings.i18n.howToTooltip}
                                    >
                                        ?
                                    </button>
                                    <span className="avc-tooltip-content" role="tooltip">
                                        {window.avcSettings.i18n.howToTooltip}
                                    </span>
                                </span>
                            </label>
                            <div className="avc-guest-link">
                                <ul className="avc-guest-collab-list">
                                    <li
                                        className="avc-guest-collab-link"
                                        dangerouslySetInnerHTML={{__html: window.avcSettings.i18n.howToText1}}
                                    />
                                    <li
                                        className="avc-guest-collab-link"
                                        dangerouslySetInnerHTML={{__html: window.avcSettings.i18n.howToText2}}
                                    />
                                    <li
                                        className="avc-guest-collab-link"
                                        dangerouslySetInnerHTML={{__html: window.avcSettings.i18n.howToText3}}
                                    />
                                </ul>
                                <Button
                                    onClick={() => {
                                        document.dispatchEvent(
                                            new CustomEvent('atarim:open-share')
                                        );
                                    }}
                                    variant="primary">
                                    {window.avcSettings.i18n.sharingOptions}
                                </Button>
                            </div>
                        </div>

                        <div className="avc-setting-block avc-who-can-collaborate">
                            <label className="components-base-control__label avc-label-with-tooltip">
                                <span>{window.avcSettings.i18n.whoCan}</span>
                                <span className="avc-tooltip">
                                    <button
                                        type="button"
                                        className="avc-tooltip-trigger"
                                        aria-label={window.avcSettings.i18n.whoCanTooltip}
                                    >
                                        ?
                                    </button>
                                    <span className="avc-tooltip-content" role="tooltip">
                                        {window.avcSettings.i18n.whoCanTooltip}
                                    </span>
                                </span>
                            </label>
                            <CreatableSelect
                                isMulti
                                value={settings.avc_selected_role.map(role => ({ label: role, value: role }))}
                                options={window.avcSettings.availableRoles.map(role => ({ label: role.label, value: role.value }))}
                                onChange={(selected) => updateSetting('avc_selected_role', selected.map(item => item.value))}
                            />
                        </div>

                        <div className="avc-setting-block avc-auto-login-user">
                            <label className="components-base-control__label avc-label-with-tooltip">
                                <span>{window.avcSettings.i18n.autoLoginAs}</span>
                                <span className="avc-tooltip">
                                    <button
                                        type="button"
                                        className="avc-tooltip-trigger"
                                        aria-label={window.avcSettings.i18n.autoLoginAsTooltip}
                                    >
                                        ?
                                    </button>
                                    <span className="avc-tooltip-content" role="tooltip">
                                        {window.avcSettings.i18n.autoLoginAsTooltip}
                                    </span>
                                </span>
                            </label>
                            <SelectControl
                                value={settings.avc_website_developer}
                                options={[
                                    { label: window.avcSettings.i18n.selectUserPlaceholder, value: '' },
                                    ...window.avcSettings.users.map((user) => ({
                                        label: `${user.display_name} (${user.user_email})`,
                                        value: user.user_email,
                                    })),
                                ]}
                                onChange={(value) => updateSetting('avc_website_developer', value)}
                            />
                        </div>

                        <div className="avc-setting-block avc-enable-doit">
                            <label className="avc-label-with-tooltip avc-checkbox-label">
                                <input
                                    type="checkbox"
                                    className="avc-doit-checkbox"
                                    checked={!!settings.avc_enable_doit}
                                    onChange={(e) => updateSetting('avc_enable_doit', e.target.checked)}
                                />
                                <span dangerouslySetInnerHTML={{ __html: window.avcSettings.i18n.enableDoit }} />
                                <span className="avc-tooltip">
                                    <button
                                        type="button"
                                        className="avc-tooltip-trigger"
                                        aria-label={window.avcSettings.i18n.enableDoitTooltip}
                                        onClick={(e) => e.preventDefault()}
                                    >
                                        ?
                                    </button>
                                    <span className="avc-tooltip-content" role="tooltip">
                                        {window.avcSettings.i18n.enableDoitTooltip}
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div className="avc-setting-block">
                            <Button variant="primary" onClick={saveAllSettings}>
                                {window.avcSettings.i18n.saveButton}
                            </Button>
                        </div>
                    </>
                )}

                {successMessage && (
                    <div className="avc_success_message" style={{ marginTop: '10px', color: 'green' }}>{successMessage}</div>
                )}
            </div>

            <div className="avc-notice-column">
                <Notice
                    pluginVersion={window.avcSettings.pluginVersion}
                    notice={window.avcSettings.notice}
                />
            </div>
        </div>
    );
};

const container = document.getElementById('avc-settings-root');

if (container) {
    const root = createRoot(container);
    root.render(<CollaborationSettings />);
}