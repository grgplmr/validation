(function () {
    if (typeof window === 'undefined' || typeof window.wp === 'undefined' || typeof window.SPAValidationSidebarData === 'undefined') {
        return;
    }

    var data = window.SPAValidationSidebarData;
    var wp = window.wp;

    var createElement = wp.element.createElement;
    var Button = wp.components.Button;
    var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
    var registerPlugin = wp.plugins.registerPlugin;

    var hasModerators = data.totalModerators > 0;
    var thresholdReached = hasModerators && data.totalApproved >= data.required;

    var statusText;
    var statusColor = '#b32d2e';

    if (!hasModerators) {
        statusText = 'Aucun modérateur (administrateur ou éditeur) trouvé.';
    } else if (thresholdReached) {
        statusText = 'Seuil atteint, article prêt à être publié.';
        statusColor = 'green';
    } else {
        statusText = 'Validations insuffisantes.';
    }

    var submitToggleForm = function () {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = data.toggleUrl;

        var actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'action';
        actionField.value = 'spa_toggle_approval';
        form.appendChild(actionField);

        var postField = document.createElement('input');
        postField.type = 'hidden';
        postField.name = 'post_id';
        postField.value = data.postId;
        form.appendChild(postField);

        var nonceField = document.createElement('input');
        nonceField.type = 'hidden';
        nonceField.name = 'spa_nonce';
        nonceField.value = data.nonce;
        form.appendChild(nonceField);

        document.body.appendChild(form);
        form.submit();
    };

    var ValidationPanel = function () {
        var children = [
            createElement('p', {}, 'Validations : ' + data.totalApproved + ' / ' + data.totalModerators),
            createElement('p', {}, 'Seuil requis : ' + data.required + ' validation(s) minimum.'),
            createElement('p', { style: { fontWeight: 'bold', color: statusColor } }, statusText)
        ];

        if (data.currentUserCanToggle) {
            children.push(
                createElement(
                    Button,
                    {
                        isPrimary: true,
                        onClick: submitToggleForm
                    },
                    data.currentUserHasApproved ? 'Retirer mon approbation' : 'Approuver cet article'
                )
            );
        }

        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'spa-validation-panel',
                title: 'Validations des modérateurs',
                className: 'spa-validation-panel'
            },
            children
        );
    };

    registerPlugin('spa-validation-plugin-sidebar', {
        render: ValidationPanel
    });
})();
