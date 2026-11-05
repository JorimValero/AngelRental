const accountSidebar = document.querySelector('.account-sidebar');
const accountSidebarToggle = accountSidebar?.querySelector('.account-sidebar-toggle');
const accountLayout = document.querySelector('.account-layout');

if (!(accountSidebar instanceof HTMLElement)
    || !(accountSidebarToggle instanceof HTMLButtonElement)
    || !(accountLayout instanceof HTMLElement)) {
    throw new Error('Account sidebar controls are missing.');
}

const setAccountSidebarExpanded = (expanded) => {
    accountLayout.classList.toggle('account-sidebar-expanded', expanded);
    accountSidebarToggle.setAttribute('aria-expanded', String(expanded));
    accountSidebarToggle.setAttribute(
        'aria-label',
        expanded ? 'Collapse account sidebar' : 'Expand account sidebar',
    );
};

accountSidebarToggle.addEventListener('click', () => {
    setAccountSidebarExpanded(accountSidebarToggle.getAttribute('aria-expanded') !== 'true');
});

document.addEventListener('click', (event) => {
    if (event.target instanceof Node && !accountSidebar.contains(event.target)) {
        setAccountSidebarExpanded(false);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && accountSidebarToggle.getAttribute('aria-expanded') === 'true') {
        setAccountSidebarExpanded(false);
        accountSidebarToggle.focus();
    }
});

window.matchMedia('(min-width: 701px)').addEventListener('change', (event) => {
    if (event.matches) {
        setAccountSidebarExpanded(false);
    }
});
