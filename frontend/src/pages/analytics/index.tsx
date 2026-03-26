import { useTranslation } from 'react-i18next'

export default function AnalyticsPage() {
  const { t } = useTranslation()
  
  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-xl md:text-2xl font-bold">{t('analytics.title')}</h1>
        <p className="text-muted-foreground mt-2">{t('analytics.detailedSubtitle')}</p>
      </div>
      <p>{t('analytics.developing')}</p>
    </div>
  )
}

