import * as React from "react"
import { CalendarIcon, ChevronLeft, ChevronRight } from "lucide-react"
import { format } from "date-fns"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"

interface DateTimePickerProps {
  date?: Date
  setDate: (date: Date | undefined) => void
  disabled?: boolean
}

type Step = 'date' | 'hour' | 'minute'

export function DateTimePicker({ date, setDate, disabled }: DateTimePickerProps) {
  const [open, setOpen] = React.useState(false)
  const [step, setStep] = React.useState<Step>('date')
  const [currentMonth, setCurrentMonth] = React.useState(new Date())
  const [selectedDate, setSelectedDate] = React.useState<Date | undefined>(date)
  
  // 默认时间为当前时间（只在初始化时计算一次）
  const [selectedHour, setSelectedHour] = React.useState<number>(() => 
    date ? date.getHours() : new Date().getHours()
  )
  const [selectedMinute, setSelectedMinute] = React.useState<number>(() => {
    if (date) return date.getMinutes()
    // 四舍五入到最近的5分钟
    const currentMinute = new Date().getMinutes()
    return Math.round(currentMinute / 5) * 5
  })

  React.useEffect(() => {
    if (date) {
      setSelectedDate(date)
      setCurrentMonth(date)
      setSelectedHour(date.getHours())
      setSelectedMinute(date.getMinutes())
    }
  }, [date])

  const getDaysInMonth = (date: Date) => {
    const year = date.getFullYear()
    const month = date.getMonth()
    const firstDay = new Date(year, month, 1)
    const lastDay = new Date(year, month + 1, 0)
    
    // 获取第一天是星期几（0=周日, 1=周一, ..., 6=周六）
    let firstDayOfWeek = firstDay.getDay()
    // 转换为周一开始（0=周一, 1=周二, ..., 6=周日）
    firstDayOfWeek = firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1
    
    const days = []
    
    // 添加上月的日期（填充空白）
    for (let i = 0; i < firstDayOfWeek; i++) {
      days.push(null)
    }
    
    // 添加本月的日期
    for (let i = 1; i <= lastDay.getDate(); i++) {
      days.push(new Date(year, month, i))
    }
    
    return days
  }

  const handleDateSelect = (newDate: Date) => {
    setSelectedDate(newDate)
    setStep('hour')
  }

  const handleHourSelect = (hour: number) => {
    setSelectedHour(hour)
    setStep('minute')
  }

  const handleMinuteSelect = (minute: number) => {
    setSelectedMinute(minute)
    
    if (selectedDate) {
      const finalDate = new Date(selectedDate)
      finalDate.setHours(selectedHour, minute, 0, 0)
      setDate(finalDate)
      setOpen(false)
      setStep('date')
    }
  }

  const handleBack = () => {
    if (step === 'minute') {
      setStep('hour')
    } else if (step === 'hour') {
      setStep('date')
    }
  }

  const previousMonth = () => {
    setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1))
  }

  const nextMonth = () => {
    setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1))
  }

  const isToday = (date: Date) => {
    const today = new Date()
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear()
  }

  const isSelected = (date: Date) => {
    if (!selectedDate) return false
    return date.getDate() === selectedDate.getDate() &&
           date.getMonth() === selectedDate.getMonth() &&
           date.getFullYear() === selectedDate.getFullYear()
  }

  // 已移除过去日期限制，允许选择任何日期

  const hours = Array.from({ length: 24 }, (_, i) => i)
  const minutes = Array.from({ length: 12 }, (_, i) => i * 5)
  const weekDays = ['一', '二', '三', '四', '五', '六', '日']

  return (
    <Popover open={open} onOpenChange={(newOpen) => {
      setOpen(newOpen)
      if (!newOpen) {
        setStep('date')
      }
    }}>
      <PopoverTrigger asChild>
        <Button
          variant={"outline"}
          className={cn(
            "w-full justify-start text-left font-normal",
            !date && "text-muted-foreground"
          )}
          disabled={disabled}
        >
          <CalendarIcon className="mr-2 h-4 w-4" />
          {date ? (
            format(date, "yyyy-MM-dd HH:mm")
          ) : (
            <span>选择日期和时间</span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[320px] p-0" align="start" side="top">
        {step === 'date' && (
          <div className="p-4">
            {/* 月份导航 */}
            <div className="flex items-center justify-between mb-4">
              <Button
                variant="outline"
                size="sm"
                onClick={previousMonth}
                className="h-7 w-7 p-0"
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <div className="text-sm font-medium">
                {format(currentMonth, 'yyyy年MM月')}
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={nextMonth}
                className="h-7 w-7 p-0"
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>

            {/* 星期标题 */}
            <div className="grid grid-cols-7 gap-1 mb-2">
              {weekDays.map(day => (
                <div key={day} className="h-9 flex items-center justify-center text-xs text-muted-foreground">
                  {day}
                </div>
              ))}
            </div>

            {/* 日期网格 */}
            <div className="grid grid-cols-7 gap-1">
              {getDaysInMonth(currentMonth).map((day, index) => {
                if (!day) {
                  return <div key={`empty-${index}`} className="h-9" />
                }

                const isTodayDate = isToday(day)
                const isSelectedDate = isSelected(day)

                return (
                  <Button
                    key={index}
                    variant={isSelectedDate ? 'default' : 'ghost'}
                    className={cn(
                      "h-9 w-full p-0 font-normal",
                      isTodayDate && !isSelectedDate && "bg-accent"
                    )}
                    onClick={() => handleDateSelect(day)}
                  >
                    {day.getDate()}
                  </Button>
                )
              })}
            </div>
          </div>
        )}
        
        {step === 'hour' && (
          <div className="p-4">
            <div className="flex items-center gap-2 mb-4">
              <Button
                variant="ghost"
                size="sm"
                onClick={handleBack}
                className="h-8 w-8 p-0"
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <h3 className="text-sm font-medium flex-1">
                选择小时 - {selectedDate && format(selectedDate, 'yyyy年MM月dd日')}
              </h3>
            </div>
            <div className="grid grid-cols-6 gap-2 max-h-[300px] overflow-y-auto">
              {hours.map(hour => (
                <Button
                  key={hour}
                  variant={selectedHour === hour ? 'default' : 'outline'}
                  className="h-10"
                  onClick={() => handleHourSelect(hour)}
                >
                  {hour.toString().padStart(2, '0')}
                </Button>
              ))}
            </div>
          </div>
        )}
        
        {step === 'minute' && (
          <div className="p-4">
            <div className="flex items-center gap-2 mb-4">
              <Button
                variant="ghost"
                size="sm"
                onClick={handleBack}
                className="h-8 w-8 p-0"
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <h3 className="text-sm font-medium flex-1">
                选择分钟 - {selectedDate && format(selectedDate, 'yyyy年MM月dd日')} {selectedHour.toString().padStart(2, '0')}:
              </h3>
            </div>
            <div className="grid grid-cols-4 gap-2">
              {minutes.map(minute => (
                <Button
                  key={minute}
                  variant={selectedMinute === minute ? 'default' : 'outline'}
                  className="h-10"
                  onClick={() => handleMinuteSelect(minute)}
                >
                  {minute.toString().padStart(2, '0')}
                </Button>
              ))}
            </div>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}

