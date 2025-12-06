(function () {
    if (!window.wp || !window.SPAValidationSidebarData) {
        return;
    }

    const { registerPlugin } = window.wp.plugins;
    const { PluginDocumentSettingPanel } = window.wp.editPost;
    const { Button } = window.wp.components;
    const { createElement: el, Fragment } = window.wp.element;

    const data = window.SPAValidationSidebarData;

    const statusColors = {
        ok: '#008000',
        warning: '#ff4e00',
        error: '#b32d2e',
        pending: '#6c757d',
        approved: '#008000',
        change: '#ff4e00',
    };

    const buttonBaseStyle = {
        display: 'block',
        width: '100%',
        maxWidth: '260px',
        marginBottom: '2px',
        color: '#ffffff',
    };

    const buildStatus = () => {
        if (data.totalModerators === 0) {
            return {
                text: 'Aucun modérateur (administrateur ou éditeur) trouvé.',
                color: statusColors.error,
            };
        }

        if (data.totalChangeRequests > 0) {
            return {
                text: `Modifications demandées par ${data.totalChangeRequests} modérateur(s).`,
                color: statusColors.warning,
            };
        }

        if (data.required > 0 && data.totalApproved >= data.required) {
            return {
                text: 'Seuil atteint, article prêt à être publié.',
                color: statusColors.ok,
            };
        }

        return {
            text: 'Validations insuffisantes.',
            color: statusColors.error,
        };
    };

    const formatTimestamp = (ts) => {
        if (!ts) {
            return null;
        }
        const date = new Date(parseInt(ts, 10) * 1000);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        const pad = (n) => (n < 10 ? `0${n}` : `${n}`);
        return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };

    const createFormAndSubmit = (action, nonce) => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = data.toggleUrl;

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        const postInput = document.createElement('input');
        postInput.type = 'hidden';
        postInput.name = 'post_id';
        postInput.value = data.postId;
        form.appendChild(postInput);

        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'spa_nonce';
        nonceInput.value = nonce;
        form.appendChild(nonceInput);

        document.body.appendChild(form);
        form.submit();
    };

    const ValidationPanel = () => {
        const status = buildStatus();
        const formattedChangesDone = formatTimestamp(data.changesDoneLast);

        const renderModerators = () => {
            if (!Array.isArray(data.moderators)) {
                return null;
            }

            return el(
                'ul',
                { style: { margin: 0, paddingLeft: '18px' } },
                data.moderators.map((moderator) => {
                    let label = 'En attente';
                    let color = statusColors.pending;

                    if (moderator.status === 'approved') {
                        label = 'Approuvé';
                        color = statusColors.approved;
                    } else if (moderator.status === 'change') {
                        label = 'Modification demandée';
                        color = statusColors.change;
                    }

                    return el(
                        'li',
                        { key: moderator.id },
                        `${moderator.name} — `,
                        el('span', { style: { color, fontWeight: moderator.status === 'pending' ? 'normal' : '600' } }, label)
                    );
                })
            );
        };

        const renderButtons = () => {
            if (!data.currentUserCanToggle) {
                return null;
            }

            return el(
                'div',
                { style: { marginTop: '12px' } },
                el(
                    Button,
                    {
                        style: { ...buttonBaseStyle, backgroundColor: '#2cd81f', borderColor: '#2cd81f' },
                        onClick: () => createFormAndSubmit('spa_toggle_approval', data.approvalNonce),
                    },
                    data.currentUserHasApproved ? 'Retirer mon approbation' : 'Approuver cet article'
                ),
                el(
                    Button,
                    {
                        style: { ...buttonBaseStyle, backgroundColor: '#ff4e00', borderColor: '#ff4e00' },
                        onClick: () => createFormAndSubmit('spa_toggle_change_request', data.changeNonce),
                    },
                    data.currentUserRequestedChanges ? 'Retirer la demande de modification' : 'Modifier cet article'
                ),
                data.totalChangeRequests > 0
                    ? el(
                          Button,
                          {
                              style: { ...buttonBaseStyle, backgroundColor: '#0073aa', borderColor: '#0073aa' },
                              onClick: () => createFormAndSubmit('spa_notify_changes_done', data.changesDoneNonce),
                          },
                          'Modifications effectuées'
                      )
                    : null
            );
        };

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'spa-validation-panel',
                title: 'Validations des modérateurs',
                className: 'spa-validation-panel',
            },
            el(
                Fragment,
                null,
                el('p', null, `Validations : ${data.totalApproved} / ${data.totalModerators}`),
                el('p', null, `Seuil requis : ${data.required} validation(s) minimum.`),
                el('p', { style: { fontWeight: 'bold', color: status.color } }, status.text),
                formattedChangesDone
                    ? el('p', { style: { marginTop: '-4px', color: '#555d66' } }, `Dernière notification de modifications effectuées : ${formattedChangesDone}`)
                    : null,
                renderModerators(),
                renderButtons()
            )
        );
    };

    registerPlugin('spa-validation-plugin-sidebar', { render: ValidationPanel });
})();
