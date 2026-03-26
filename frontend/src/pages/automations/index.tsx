import { useTranslation } from 'react-i18next'

export default function AutomationsPage() {
  const { t } = useTranslation()
  
  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-xl md:text-2xl font-bold">{t('automations.title')}</h1>
        <p className="text-muted-foreground mt-2">{t('automations.subtitle')}</p>
      </div>
      <p>{t('automations.developing')}</p>
    </div>
  )
}

