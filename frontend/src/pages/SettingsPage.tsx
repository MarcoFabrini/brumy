import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Building2,
  ArrowLeftRight,
  Globe,
  ListChecks,
  KeyRound,
  LogOut,
  Mail,
  Moon,
  Sun,
  Type,
} from 'lucide-react';
import {
  Badge,
  ListRow,
  ListSection,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
} from '@/components/ui';
import { EmailVerificationBanner, PageHeader } from '@/components/layout';
import {
  ChangePasswordDialog,
  EditOrganizationDialog,
  EditProfileDialog,
  MembersCard,
  OrganizationSwitcher,
  PushSettingsCard,
  UserAvatar,
} from '@/components/features';
import { useAuthStore } from '@/stores/useAuthStore';
import { useThemeStore, type Theme } from '@/stores/useThemeStore';
import { useLogout } from '@/hooks/useLogout';
import { useUpdateProfile } from '@/hooks/useAccount';
import { useActiveMembership } from '@/hooks/useActiveMembership';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import i18n from '@/i18n';

type DialogKey = 'profile' | 'password' | 'org' | null;

/** Select come "pop-up button" di iOS: solo il valore in grigio con le frecce, senza riquadro. */
const IOS_POPUP_TRIGGER = 'h-auto min-h-touch w-auto gap-1 border-0 bg-transparent px-0 text-[1.0625rem] text-muted-foreground shadow-none';

export function SettingsPage() {
  const { t } = useTranslation();
  const user = useAuthStore((s) => s.user);
  const theme = useThemeStore((s) => s.theme);
  const setTheme = useThemeStore((s) => s.setTheme);
  const largeText = useThemeStore((s) => s.largeText);
  const setLargeText = useThemeStore((s) => s.setLargeText);
  const logout = useLogout();
  const updateProfile = useUpdateProfile();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const languageLabelId = useId();
  const themeLabelId = useId();
  const largeTextLabelId = useId();

  const [dialog, setDialog] = useState<DialogKey>(null);
  const [loggingOut, setLoggingOut] = useState(false);

  // Il logout è asincrono (push, revoca refresh token): senza guardia un secondo tap lo ripete.
  async function onLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      setLoggingOut(false);
    }
  }

  /**
   * Lingua unificata: cambia subito la UI (i18next, ottimistico) e persiste il
   * locale sul profilo backend, così email/notifiche e UI restano allineate.
   * Se il salvataggio fallisce: rollback della UI + toast d'errore, altrimenti
   * il desync resterebbe silenzioso fino al prossimo reload di /me.
   */
  function changeLanguage(locale: 'it' | 'en') {
    if (!user) return;
    const previous = i18n.language.startsWith('en') ? 'en' : 'it';
    if (locale === previous) return;

    void i18n.changeLanguage(locale);
    updateProfile.mutate(
      {
        email: user.email,
        firstName: user.firstName,
        lastName: user.lastName,
        locale,
      },
      {
        onError: (e) => {
          void i18n.changeLanguage(previous);
          toast({ title: errorMessage(e), variant: 'error' });
        },
      },
    );
  }

  const membership = useActiveMembership();
  const canEditOrg = membership?.role === 'owner' || membership?.role === 'admin';

  return (
    <div className="space-y-6">
      <PageHeader title={t('nav.tabs.profile')} />
      <EmailVerificationBanner />

      {/* ── Account: riga con avatar (apre la modifica del profilo), email e password ───────────── */}
      {user && (
        <ListSection title={t('settings.profile')}>
          <ListRow
            as="button"
            type="button"
            onClick={() => setDialog('profile')}
            aria-label={t('account.edit_profile')}
            className="py-1.5"
            label={
              <span className="flex items-center gap-3 py-1">
                <UserAvatar user={user} />
                <span className="min-w-0">
                  <span className="block truncate text-xl font-semibold">
                    {user.firstName} {user.lastName}
                  </span>
                  <span className="block truncate text-[0.9375rem] text-muted-foreground">{user.email}</span>
                </span>
              </span>
            }
            chevron
          />
          <ListRow
            as="button"
            type="button"
            onClick={() => setDialog('profile')}
            icon={<Mail />}
            iconClassName="bg-[#007aff]"
            label={t('account.email')}
            value={<span className="truncate">{user.email}</span>}
            chevron
          />
          <ListRow
            as="button"
            type="button"
            onClick={() => setDialog('password')}
            icon={<KeyRound />}
            iconClassName="bg-[#8e8e93]"
            label={t('account.change_password')}
            chevron
          />
        </ListSection>
      )}

      {/* ── Organizzazione ────────────────────────────────────── */}
      {membership && (
        <ListSection title={t('settings.organization')}>
          <ListRow
            {...(canEditOrg
              ? { as: 'button' as const, type: 'button' as const, onClick: () => setDialog('org'), chevron: true }
              : {})}
            icon={<Building2 />}
            iconClassName="bg-[#5856d6]"
            label={membership.organization.name}
            value={<Badge variant="secondary">{t(`settings.role.${membership.role}`)}</Badge>}
          />
          {/* Su mobile non c'è la barra in alto col nome: con più organizzazioni si cambia da qui. */}
          {(user?.memberships.length ?? 0) > 1 && (
            <ListRow
              className="md:hidden"
              icon={<ArrowLeftRight />}
              iconClassName="bg-[#34c759]"
              label={t('org.switch.label')}
              value={<OrganizationSwitcher className="min-w-0 max-w-[10rem] text-primary" />}
            />
          )}
        </ListSection>
      )}

      {/* ── Membri organizzazione (owner/admin) ───────────────── */}
      {membership && canEditOrg && user && (
        <MembersCard
          organizationId={membership.organization.id}
          currentUserId={user.id}
          currentRole={membership.role}
        />
      )}

      {/* ── Preferenze app ────────────────────────────────────── */}
      <ListSection title={t('settings.preferences')}>
        <ListRow
          icon={<Globe />}
          iconClassName="bg-[#007aff]"
          label={<span id={languageLabelId}>{t('settings.language')}</span>}
          value={
            <Select
              value={i18n.language.startsWith('en') ? 'en' : 'it'}
              onValueChange={(v) => changeLanguage(v as 'it' | 'en')}
              disabled={updateProfile.isPending}
            >
              <SelectTrigger className={IOS_POPUP_TRIGGER} aria-labelledby={languageLabelId}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent align="end">
                <SelectItem value="it">Italiano</SelectItem>
                <SelectItem value="en">English</SelectItem>
              </SelectContent>
            </Select>
          }
        />
        <ListRow
          icon={theme === 'dark' ? <Moon /> : <Sun />}
          iconClassName="bg-[#5856d6]"
          label={<span id={themeLabelId}>{t('settings.theme')}</span>}
          value={
            <Select value={theme} onValueChange={(v) => setTheme(v as Theme)}>
              <SelectTrigger className={IOS_POPUP_TRIGGER} aria-labelledby={themeLabelId}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent align="end">
                <SelectItem value="light">{t('settings.theme_light')}</SelectItem>
                <SelectItem value="dark">{t('settings.theme_dark')}</SelectItem>
                <SelectItem value="system">{t('settings.theme_system')}</SelectItem>
              </SelectContent>
            </Select>
          }
        />
        <ListRow
          icon={<Type />}
          iconClassName="bg-[#007aff]"
          label={<span id={largeTextLabelId}>{t('settings.large_text')}</span>}
          value={<Switch checked={largeText} onCheckedChange={setLargeText} aria-labelledby={largeTextLabelId} />}
        />
      </ListSection>

      {/* ── Elenchi personali: distributori, officine, categorie ── */}
      <ListSection>
        <ListRow
          as={Link}
          to="/settings/values"
          icon={<ListChecks />}
          iconClassName="bg-[#ff9500]"
          label={t('values.title')}
          chevron
        />
      </ListSection>

      {/* ── Notifiche push: sottoscrizione dispositivo (tutti) + VAPID (solo owner) ── */}
      <PushSettingsCard />

      {/* ── Logout: riga rossa come in iOS ───────────────────── */}
      <ListSection>
        <ListRow
          as="button"
          type="button"
          disabled={loggingOut}
          onClick={() => void onLogout()}
          destructive
          className="justify-center disabled:opacity-50"
          label={
            <span className="flex items-center justify-center gap-2">
              {loggingOut ? <Spinner size="sm" /> : <LogOut className="size-5" />}
              {t('auth.logout')}
            </span>
          }
        />
      </ListSection>

      {/* ── Modali ────────────────────────────────────────────── */}
      {user && (
        <>
          <EditProfileDialog
            open={dialog === 'profile'}
            onOpenChange={(o) => setDialog(o ? 'profile' : null)}
            user={user}
          />
          <ChangePasswordDialog
            open={dialog === 'password'}
            onOpenChange={(o) => setDialog(o ? 'password' : null)}
          />
          {membership && (
            <EditOrganizationDialog
              open={dialog === 'org'}
              onOpenChange={(o) => setDialog(o ? 'org' : null)}
              organizationId={membership.organization.id}
              currentName={membership.organization.name}
            />
          )}
        </>
      )}
    </div>
  );
}
