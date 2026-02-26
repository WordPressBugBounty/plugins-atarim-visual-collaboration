import { useState } from '@wordpress/element';

const Notice = ({ pluginVersion, notice }) => {
    // If config or version is missing, don't render anything
    if (! pluginVersion || ! notice) {
        return null;
    }

    const shouldShowNoticeForVersion =
        notice.enabled &&
        Array.isArray(notice.showForVersions) &&
        notice.showForVersions.includes(pluginVersion);

    // One shared localStorage key for all notices
    const noticeStorageKey = 'avc_notice_dismissed_version';

    const [visible, setVisible] = useState(() => {
        if (! shouldShowNoticeForVersion) {
            return false;
        }

        try {
            const dismissedVersion = localStorage.getItem(noticeStorageKey);
            return dismissedVersion !== pluginVersion;
        } catch (e) {
            return true;
        }
    });

    const handleDismiss = () => {
        setVisible(false);

        if (! pluginVersion) return;

        try {
            localStorage.setItem(noticeStorageKey, pluginVersion);
        } catch (e) {
            // Ignore storage errors
        }
    };

    // After the initial check, if we shouldn’t show, bail early
    if (! shouldShowNoticeForVersion || ! visible) {
        return null;
    }

    return (
        <div className="avc-notice">
            <div className="avc-notice-content">
                {notice.title && (
                    <div className="avc-notice-title">
                        {notice.title}
                    </div>
                )}

                {notice.descriptionHtml && (
                    <p
                        className="avc-notice-description"
                        dangerouslySetInnerHTML={{ __html: notice.descriptionHtml }}
                    />
                )}
            </div>

            <button
                type="button"
                aria-label="Dismiss notice"
                className="avc-notice-close"
                onClick={handleDismiss}
            >
                ×
            </button>
        </div>
    );
};

export default Notice;
