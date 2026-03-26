import { useTranslation } from 'react-i18next'

export default function AutomationBuilderPage() {
  const { t } = useTranslation()
  
  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-xl md:text-2xl font-bold">{t('automations.builderTitle')}</h1>
        <p className="text-muted-foreground mt-2">{t('automations.builderSubtitle')}</p>
      </div>
      <p>{t('automations.builderDeveloping')}</p>
    </div>
  )
}

