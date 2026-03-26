import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Eye, Save } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

interface Category {
  value: string
  label: string
}

export default function TemplateEditorPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams()
  const queryClient = useQueryClient()
  const isEditing = !!id

  const [name, setName] = useState('')
  const [category, setCategory] = useState('general')
  const [description, setDescription] = useState('')
  const [htmlContent, setHtmlContent] = useState('')
  const [isPreviewOpen, setIsPreviewOpen] = useState(false)
  const [previewHtml, setPreviewHtml] = useState('')

  const { data: categoriesData } = useQuery({
    queryKey: ['template-categories'],
    queryFn: async () => {
      const response = await api.get('/templates/categories')
      return response.data.data as Category[]
    },
  })

  const { data: templateData, isLoading } = useQuery({
    queryKey: ['template', id],
    queryFn: async () => {
      if (!id) return null
      const response = await api.get(`/templates/${id}`)
      return response.data.data
    },
    enabled: isEditing,
  })

  useEffect(() => {
    if (templateData) {
      setName(templateData.name)
      setCategory(templateData.category)
      setDescription(templateData.description || '')
      setHtmlContent(templateData.html_content)
    }
  }, [templateData])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const data = {
        name,
        category,
        description,
        html_content: htmlContent,
      }

      if (isEditing) {
        return await api.put(`/templates/${id}`, data)
      } else {
        return await api.post('/templates', data)
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['templates'] })
      toast.success(isEditing ? t('templateEditor.updateSuccess') : t('templateEditor.createSuccess'))
      navigate('/templates')
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || t('templateEditor.saveFailed'))
    },
  })

  const handlePreview = async () => {
    let preview = htmlContent
    const previewData: Record<string, string> = {
      '{email}': 'subscriber@example.com',
      '{first_name}': 'John',
      '{last_name}': 'Doe',
      '{full_name}': 'John Doe',
      '{campaign_id}': '123',
      '{date}': new Date().toLocaleDateString().replace(/\//g, ''),
      '{list_name}': t('templateEditor.sampleList'),
      '{server_name}': t('templateEditor.sampleServer'),
      '{sender_domain}': 'example.com',
      '{unsubscribe_url}': '#',
    }

    Object.entries(previewData).forEach(([key, value]) => {
      preview = preview.replace(new RegExp(key, 'g'), value)
    })

    setPreviewHtml(preview)
    setIsPreviewOpen(true)
  }

  const handleSave = () => {
    if (!name) {
      toast.error(t('templateEditor.pleaseEnterName'))
      return
    }
    if (!htmlContent) {
      toast.error(t('templateEditor.pleaseEnterContent'))
      return
    }
    saveMutation.mutate()
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p>{t('templateEditor.loading')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate('/templates')}
          >
            <ArrowLeft className="w-4 h-4" />
          </Button>
          <div>
            <h1 className="text-xl md:text-2xl font-bold">
              {isEditing ? t('templateEditor.editTemplate') : t('templateEditor.createTemplate')}
            </h1>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handlePreview}>
            <Eye className="w-4 h-4 mr-2" />
            {t('templateEditor.preview')}
          </Button>
          <Button onClick={handleSave} disabled={saveMutation.isPending}>
            <Save className="w-4 h-4 mr-2" />
            {saveMutation.isPending ? t('templateEditor.saving') : t('templateEditor.save')}
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-1">
          <Card>
            <CardHeader>
              <CardTitle>{t('templateEditor.basicInfo')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">{t('templateEditor.templateNameRequired')}</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={t('templateEditor.templateNamePlaceholder')}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="category">{t('templateEditor.categoryRequired')}</Label>
                <Select value={category} onValueChange={setCategory}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {categoriesData?.map((cat: Category) => (
                      <SelectItem key={cat.value} value={cat.value}>
                        {cat.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="description">{t('templateEditor.description')}</Label>
                <Textarea
                  id="description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder={t('templateEditor.descriptionPlaceholder')}
                  rows={3}
                />
              </div>

              <div className="pt-4 border-t">
                <h4 className="text-sm font-medium mb-3">{t('templateEditor.availableVariables')}</h4>
                <div className="space-y-3">
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">{t('templateEditor.subscriberInfo')}</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{email}'}</code> - {t('templateEditor.emailAddress')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{first_name}'}</code> - {t('templateEditor.firstName')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{last_name}'}</code> - {t('templateEditor.lastName')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{full_name}'}</code> - {t('templateEditor.fullName')}</p>
                    </div>
                  </div>
                  
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">{t('templateEditor.systemVariables')}</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{campaign_id}'}</code> - {t('templateEditor.campaignId')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{date}'}</code> - {t('templateEditor.dateFormat')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{list_name}'}</code> - {t('templateEditor.listName')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{server_name}'}</code> - {t('templateEditor.serverName')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{sender_domain}'}</code> - {t('templateEditor.senderDomain')}</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{unsubscribe_url}'}</code> - {t('templateEditor.unsubscribeUrl')}</p>
                    </div>
                  </div>
                  
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">{t('templateEditor.customTags')}</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p className="italic">{t('templateEditor.customTagsHint')}</p>
                      <p>{t('templateEditor.customTagsUsage')}</p>
                      <p className="text-[10px] text-muted-foreground/70 mt-1">
                        {t('templateEditor.customTagsExample')}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>{t('templateEditor.htmlContent')}</CardTitle>
            </CardHeader>
            <CardContent>
              <Textarea
                value={htmlContent}
                onChange={(e) => setHtmlContent(e.target.value)}
                placeholder={t('templateEditor.htmlPlaceholder')}
                className="font-mono text-sm min-h-[600px]"
              />
            </CardContent>
          </Card>
        </div>
      </div>

      <Dialog open={isPreviewOpen} onOpenChange={setIsPreviewOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{t('templateEditor.templatePreview')}</DialogTitle>
          </DialogHeader>
          <div className="border rounded-lg p-4 bg-white">
            <div dangerouslySetInnerHTML={{ __html: previewHtml }} />
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

