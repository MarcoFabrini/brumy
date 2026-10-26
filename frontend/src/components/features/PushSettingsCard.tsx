import { useEffect, useId, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { BellRing, KeyRound, RefreshCw, Send } from 'lucide-react';
import {
  Button,
  FormField,
  Input,
  ListRow,
  ListSection,
  Spinner,
  Switch,
  Text,
} from '@/components/ui';
import { ConfirmDialog } from './ConfirmDialog';
import { SettingsSourceBadge } from './SettingsSourceBadge';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { usePushSubscription } from '@/hooks/usePushSubscription';
import {
  useGenerateVapidKeys,
  usePushSettings,
  useSavePushSettings,
  useTestPush,
} from '@/hooks/usePushSettings';
import { useAuthStore } from '@/stores/useAuthStore';
import { pushSettingsSchema, type PushSettingsFormData } from '@/schemas/pushSettings.schema';

/**
 * PushSettingsCard — card unica "Notifiche": sottoscrizione push del proprio
 * dispositivo (tutti gli utenti) + configurazione VAPID di istanza (solo
 * amministratore di istanza, sotto separatore). Prima erano due card separate: dispersivo, dato
 * che riguardano la stessa feature vista da due angolazioni diverse.
 */
export function PushSettingsCard() {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();

  const push = usePushSubscription();

  const user = useAuthStore((s) => s.user);
  const isInstanceAdmin = user?.isInstanceAdmin ?? false;

  const settings = usePushSettings(isInstanceAdmin);
  const save = useSavePushSettings();
  const generate = useGenerateVapidKeys();
  const test = useTestPush();

  const [confirmRegen, setConfirmRegen] = useState(false);

  const form = useForm<PushSettingsFormData>({
    resolver: zodResolver(pushSettingsSchema),
    defaultValues: { subject: '' },
  });

  useEffect(() => {
    if (settings.data) {
      form.reset({ subject: settings.data.subject });
    }
  }, [settings.data, form]);

  const errors = form.formState.errors;
  const hasKeys = settings.data?.hasKeys ?? false;

  function submit(data: PushSettingsFormData) {
    save.mutate(
      { subject: data.subject || undefined },
      {
        onSuccess: () => toast({ title: t('settings.push.saved'), variant: 'success' }),
        onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
      },
    );
  }

  function doGenerate() {
    generate.mutate(undefined, {
      onSuccess: (res) =>
        toast({
          title: t('settings.push.generated', { count: res.removedSubscriptions }),
          variant: 'success',
        }),
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
  }

  async function toggleSubscription(action: 'subscribe' | 'unsubscribe') {
    try {
      await (action === 'subscribe' ? push.subscribe() : push.unsubscribe());
    } catch (e) {
      toast({ title: errorMessage(e), variant: 'error' });
    }
  }

  function sendTest() {
    test.mutate(undefined, {
      onSuccess: (res) => toast({ title: t('settings.push.test_sent', { count: res.sent }), variant: 'success' }),
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
  }

  const deviceNote =
    push.status === 'unsupported'
      ? t('settings.push.unsupported')
      : push.status === 'sw-unavailable'
        ? t('settings.push.sw_unavailable')
        : push.status === 'not-configured'
          ? t('settings.push.not_configured')
          : push.status === 'denied'
            ? t('settings.push.denied')
            : push.status === 'loading'
              ? t('actions.loading')
              : undefined;
  const deviceLabelId = useId();
  const canToggle = push.status === 'subscribed' || push.status === 'unsubscribed';

  return (
    <>
      {/* Una sola card per tutto ciò che riguarda le notifiche: questo dispositivo (tutti) e, sotto lo stesso
          gruppo, la configurazione VAPID di istanza (solo instance admin). */}
      <ListSection title={t('settings.notifications')}>
        <ListRow
          icon={<BellRing />}
          iconClassName="bg-[#ff3b30]"
          label={<span id={deviceLabelId}>{t('settings.push.this_device')}</span>}
          description={deviceNote}
          value={
            canToggle ? (
              <Switch
                checked={push.status === 'subscribed'}
                onCheckedChange={(on) => void toggleSubscription(on ? 'subscribe' : 'unsubscribe')}
                aria-labelledby={deviceLabelId}
              />
            ) : (
              <span className="text-[0.9375rem]">{t('settings.push.unavailable')}</span>
            )
          }
        />

        {isInstanceAdmin && (
          <div className="space-y-3 p-4">
            <h3 className="flex items-center gap-2 text-[1.0625rem]">
              {t('settings.push.server_title')}
              {settings.data && <SettingsSourceBadge source={settings.data.source} />}
            </h3>
            <Text variant="muted" className="text-[0.8125rem]">
              {t('settings.push.card_description')}
            </Text>

            {!hasKeys ? (
              <div className="space-y-3">
                <Text variant="muted" className="text-[0.8125rem]">
                  {t('settings.push.no_keys')}
                </Text>
                <Button type="button" fullWidth disabled={generate.isPending} onClick={doGenerate}>
                  {generate.isPending ? <Spinner size="sm" /> : <KeyRound />}
                  {t('settings.push.generate')}
                </Button>
              </div>
            ) : (
              <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
                <FormField label={t('settings.push.public_key')}>
                  {(id) => <Input id={id} readOnly value={settings.data?.publicKey ?? ''} className="font-mono text-xs" />}
                </FormField>

                <FormField label={t('settings.push.subject')} error={tErr(errors.subject?.message)}>
                  {(id) => <Input id={id} placeholder="mailto:admin@example.com" {...form.register('subject')} />}
                </FormField>

                <div className="flex flex-col gap-2 sm:flex-row">
                  <Button type="submit" disabled={save.isPending}>
                    {save.isPending ? <Spinner size="sm" /> : t('actions.save')}
                  </Button>
                  <Button type="button" variant="outline" disabled={test.isPending} onClick={sendTest}>
                    {test.isPending ? <Spinner size="sm" /> : <Send />}
                    {t('settings.push.send_test')}
                  </Button>
                  <Button type="button" variant="outline" disabled={generate.isPending} onClick={() => setConfirmRegen(true)}>
                    {generate.isPending ? <Spinner size="sm" /> : <RefreshCw />}
                    {t('settings.push.regenerate')}
                  </Button>
                </div>
              </form>
            )}
          </div>
        )}
      </ListSection>

      <ConfirmDialog
        open={confirmRegen}
        onOpenChange={setConfirmRegen}
        title={t('settings.push.regenerate_confirm_title')}
        description={t('settings.push.regenerate_confirm_description')}
        confirmLabel={t('settings.push.regenerate')}
        isPending={generate.isPending}
        onConfirm={doGenerate}
      />
    </>
  );
}
