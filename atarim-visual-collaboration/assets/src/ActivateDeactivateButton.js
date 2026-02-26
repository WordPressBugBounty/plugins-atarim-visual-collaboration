import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';

const ActivateDeactivateButton = ({ isCollabActive, activationUrl }) => {
    const [active, setActive] = useState(isCollabActive === 'yes');

    const handleActivate = () => {
        // Add logic to activate collaboration (e.g., API call)
        setActive(true);
    };

    const handleDeactivate = () => {
        // Add logic to deactivate collaboration
        setActive(false);
    };

    return (
        <div>
            {active ? (
                <Button
                    isSecondary
                    onClick={handleDeactivate}
                    className="avc-deactivate-project"
                >
                    {window.avcSettings.i18n.disconnect}
                </Button>
            ) : (
                <div className="avc-activate-project">
                    <Button
                        isPrimary
                        href="javascript:void(0)"
                        className="avc-activate-project avc-trigger-activate"
                        target="_blank"
                    >
                        {window.avcSettings.i18n.connect}
                    </Button>
                </div>
            )}
        </div>
    );
};

export default ActivateDeactivateButton;
